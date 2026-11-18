const crypto=require('crypto');
const path=require('path');

// Deliberately late: the original queue-size assertions have already run.
// The two sparse heads are synthetic setup; every successor uses the real UI.
module.exports=async({ctx,base,dir,docker,ok})=>{
 const baselineChecks=370;
 let newChecks=0;
 const check=(value,label)=>{ok(value,'revision ceiling: '+label);newChecks++;};
 const sql=query=>docker(['exec','-T','db','psql','-U','postgres','-d','sites_preview_http_ci',
   '-X','-v','ON_ERROR_STOP=1','-Atc',query]).trim();
 const uuid=/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;
 const snapshot=(exclude=[])=>{
   if(!exclude.every(id=>uuid.test(id)))throw Error('Invalid synthetic ceiling scope');
   return sql(['principals','projects','versions','reviews','audit'].map(table=>{
     const where=exclude.length&&table!=='principals'?' WHERE r.'+(table==='projects'?'id':'project_id')+
       ' NOT IN ('+exclude.map(id=>"'"+id+"'::uuid").join(',')+')':'';
     return "SELECT '"+table+"' AS table_name,count(*) AS row_count,"+
       "encode(sha256(convert_to(coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text,'[]'),'UTF8')),'hex') AS digest "+
       'FROM sites_preview.'+table+' r'+where;
   }).join(' UNION ALL ')+' ORDER BY table_name');
 };
 const context=await ctx('author',{javaScriptEnabled:false});
 context.setDefaultTimeout(20000);context.setDefaultNavigationTimeout(20000);
 const page=await context.newPage();
 const fields=['brand','headline','description','call_to_action','benefits'];
 const form=()=>page.getByTestId('briefing-form');
 const fill=async values=>{for(const name of fields)await form().locator('[name="'+name+'"]').fill(values[name]);};
 const payload=async()=>{
   const values={_token:await form().locator('[name="_token"]').inputValue(),product:await form().locator('#product').inputValue()};
   for(const name of fields)values[name]=await form().locator('[name="'+name+'"]').inputValue();
   if(await form().locator('[name="expected_revision"]').count())values.expected_revision=await form().locator('[name="expected_revision"]').inputValue();
   return values;
 };
 const submit=async selected=>{
   const target=new URL(await selected.getAttribute('action'),base).toString();
   const [response,loaded]=await Promise.all([
     page.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='POST'&&r.url()===target),
     page.waitForNavigation({waitUntil:'load'}),selected.locator('button[type="submit"]').click(),
   ]);
   return {response,loaded};
 };
 const readHtml=async(project,revision)=>{
   const response=await context.request.get(project+'/versions/'+revision+'/html');
   const bytes=await response.body();
   return {status:response.status(),bytes,digest:crypto.createHash('sha256').update(bytes).digest('hex'),headers:response.headers()};
 };
 const initial=snapshot();
 const cases=[];
 let editor,foreign,complete=false,terminalUrl,appendableUrl,revocationPayload;
 try {
   for(const [product,head] of [['onepage',10000],['landing_page',2147483646]]){
     await page.goto(base+'/_preview');
     await form().locator('#product').selectOption(product);
     const values={brand:'Limite HTTP '+product,headline:'Versão inicial do limite '+product,
       description:'Conteúdo sintético com https://example.invalid/ e onboarding=literal.',
       call_to_action:'Consultar',benefits:'Histórico preservado\nTexto <span data-ceiling-probe="literal">sem formato</span>'};
     await fill(values);
     const created=await submit(form());
     const url=page.url(),id=new URL(url).pathname.split('/').at(-1),first=await readHtml(url,1);
     check(created.response.status()===302&&created.loaded.status()===200&&uuid.test(id)&&first.status===200&&
       await form().locator('[name="expected_revision"]').inputValue()==='1',
       product+' starts from one project created through the real browser form and readable stored HTML');
     cases.push({product,head,url,id,values,first});
   }
   const seedArgs=['run','--rm','--no-deps','--user','0',
     '-v',process.env.PREVIEW_CI_MATERIAL+':/run/ci:ro',
     '-v',path.join(process.env.GITHUB_WORKSPACE,'tests/fixtures/preview-revision-ceiling')+':/tmp/revision-ceiling:ro',
     '-e','GITHUB_ACTIONS=true','-e','SITES_PREVIEW_HTTP_CI=1'];
   const seedCommand=['app','php','/tmp/revision-ceiling/http-seed.php',...cases.map(item=>item.id)];
   const beforeSeed=snapshot();let wrongClusterDenied=false;
   try{docker([...seedArgs,'-e','PREVIEW_EXPECTED_CLUSTER=1',...seedCommand]);}
   catch(error){wrongClusterDenied=error.status===2&&String(error.stderr||'').includes('REVISION_CEILING_HTTP_SEED_DENIED');}
   check(wrongClusterDenied&&snapshot()===beforeSeed,'late seed checks the disposable cluster identity before any write');
   const seeded=docker([...seedArgs,'-e','PREVIEW_EXPECTED_CLUSTER='+process.env.PREVIEW_EXPECTED_CLUSTER,...seedCommand]);
   if(!seeded.includes('REVISION CEILING HTTP SEED PASS:'))throw Error('Sparse ceiling seed did not finish');
   for(const item of cases){
     const response=await page.goto(item.url),high=await readHtml(item.url,item.head);
     check(response.status()===200&&await form().locator('[name="expected_revision"]').inputValue()===String(item.head)&&
       JSON.stringify(await page.locator('[data-version]').evaluateAll(nodes=>nodes.map(node=>Number(node.dataset.version))))===JSON.stringify([item.head,1])&&
       high.status===200&&high.bytes.equals(item.first.bytes),
       item.product+' sparse setup adds only the high head with exact original bytes and keeps the real form writable');
   }
   const ordinary=cases[0],terminal=cases[1];
   appendableUrl=ordinary.url;terminalUrl=terminal.url;
   await page.goto(ordinary.url);
   for(const next of [10001,10002]){
     await form().locator('[name="headline"]').fill('Versão HTTP real '+next);
     const submitted=await payload();
     const advanced=await submit(form());
     const stored=await readHtml(ordinary.url,next);
     check(advanced.response.status()===302&&advanced.loaded.status()===200&&
       submitted.expected_revision===String(next-1)&&await form().locator('[name="expected_revision"]').inputValue()===String(next)&&
       stored.status===200&&await page.locator('[data-version="'+next+'"] code').textContent()==='SHA-256: '+stored.digest&&
       stored.digest!==ordinary.first.digest,
       'real form accepts CAS'+(next-1)+' and persists readable successor'+next+' with its exact digest');
   }
   const beforeStale=snapshot();
   await form().locator('[name="headline"]').fill('Tentativa HTTP de outra aba');
   await form().locator('[name="expected_revision"]').evaluate(input=>{input.value='10001';});
   const stale=await submit(form());
   check(stale.response.status()===409&&stale.loaded.status()===409&&
     await page.getByText(/Outra versão foi criada enquanto você editava/).isVisible()&&
     await form().locator('[name="expected_revision"]').inputValue()==='10002'&&snapshot()===beforeStale,
     'a browser submission with a stale accepted high CAS returns409 and leaves every table unchanged');
   await page.goto(ordinary.url);revocationPayload=await payload();

   await page.goto(terminal.url);
   terminal.values.headline='Última versão HTTP 2147483647';
   await form().locator('[name="headline"]').fill(terminal.values.headline);
   const lastPayload=await payload(),last=await submit(form());
   const lastHtml=await readHtml(terminal.url,2147483647);
   check(lastPayload.expected_revision==='2147483646'&&last.response.status()===302&&last.loaded.status()===200&&
     lastHtml.status===200&&lastHtml.digest!==terminal.first.digest&&
     await page.locator('[data-version="2147483647"] code').textContent()==='SHA-256: '+lastHtml.digest,
     'the last writable CAS persists and serves INTEGER maximum2147483647 through the normal redirect');
   check(await page.getByTestId('revision-limit-notice').isVisible()&&
     await page.getByText('Limite de versões atingido.',{exact:true}).isVisible()&&
     await form().count()===0&&await page.locator('[name="expected_revision"]').count()===0&&
     await page.getByRole('button',{name:'Gerar nova versão privada →',exact:true}).count()===0&&
     await page.getByTestId('comparison-form').isVisible(),
     'terminal author sees a clear limit and comparison, with no append form, CAS or submit control');
   const readonly=page.getByTestId('briefing-readonly');
   let exactSaved=await readonly.locator('dd[data-field="product"]').textContent()==='Landing Page';
   for(const name of fields)exactSaved=exactSaved&&await readonly.locator('dd[data-field="'+name+'"]').textContent()===terminal.values[name];
   check(exactSaved&&await readonly.locator('dd[data-field]').count()===6&&await page.locator('[data-ceiling-probe]').count()===0,
     'terminal readonly briefing preserves all six stored fields as escaped text');
   const beforeReads=snapshot();
   const older=await page.goto(terminal.url+'?before_revision=2147483647');
   check(older.status()===200&&JSON.stringify(await page.locator('[data-version]').evaluateAll(nodes=>nodes.map(node=>Number(node.dataset.version))))===JSON.stringify([2147483646,1])&&
     await page.getByTestId('briefing-readonly').locator('dd[data-field="headline"]').textContent()===terminal.values.headline&&
     await page.getByTestId('revision-limit-notice').isVisible()&&await form().count()===0,
     'an older history window still identifies the terminal saved briefing and cannot restore editing');
   await page.getByLabel('Versão anterior',{exact:true}).fill('2147483646');
   await page.getByLabel('Versão posterior',{exact:true}).fill('2147483647');
   const [compared]=await Promise.all([
     page.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='GET'&&new URL(r.url()).searchParams.get('compare_to')==='2147483647'),
     page.waitForNavigation({waitUntil:'load'}),page.getByRole('button',{name:'Comparar versões',exact:true}).click(),
   ]);
   const panel=page.getByTestId('editorial-comparison');
   check(compared.status()===200&&new URL(page.url()).searchParams.get('before_revision')==='2147483647'&&
     await panel.locator('[data-compare-side="from"][data-compare-revision="2147483646"] code').textContent()==='SHA-256: '+terminal.first.digest&&
     await panel.locator('[data-compare-side="to"][data-compare-revision="2147483647"] code').textContent()==='SHA-256: '+lastHtml.digest&&
     await panel.locator('[data-compare-side="to"] [data-field="headline"]').textContent()===terminal.values.headline&&await form().count()===0,
     'real comparison form reads the exact terminal pair outside the selected older history window');
   const originalAgain=await readHtml(terminal.url,1),seedAgain=await readHtml(terminal.url,2147483646);
   check(originalAgain.bytes.equals(terminal.first.bytes)&&seedAgain.bytes.equals(terminal.first.bytes)&&
     lastHtml.headers['cache-control'].includes('no-store')&&lastHtml.headers['content-security-policy'].includes('sandbox')&&snapshot()===beforeReads,
     'terminal history, comparison and sandboxed HTML retain original immutable bytes without writes');
   for(const [label,width,height] of [['desktop',1440,1000],['mobile',390,844]]){
     await page.setViewportSize({width,height});
     await page.getByTestId('revision-limit-notice').scrollIntoViewIfNeeded();
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),label+' terminal layout has no horizontal overflow');
     await page.screenshot({path:dir+'/revision-ceiling-'+label+'.png',fullPage:true});
   }
   const beforeDenied=snapshot();
   const above=await context.request.post(terminal.url,{form:{...lastPayload,expected_revision:'2147483647',headline:'Tentativa terminal não salva'}});
   const aboveBody=await above.text();
   check(above.status()===422&&aboveBody.includes('Recarregue o projeto para conferir a versão atual')&&
     aboveBody.includes('Tentativa terminal não salva')&&aboveBody.includes('data-testid="revision-limit-notice"')&&
     !aboveBody.includes('data-testid="briefing-form"')&&snapshot()===beforeDenied,
     'a forged terminal POST is rejected by validation, preserves its attempted text and cannot append or expose an append form');
   const terminalStale=await context.request.post(terminal.url,{form:lastPayload});
   check(terminalStale.status()===409&&(await terminalStale.text()).includes('Outra versão foi criada')&&snapshot()===beforeDenied,
     'the former last-writable CAS remains a stale conflict after the terminal successor, without overflow or mutation');

   foreign=await ctx('other',{javaScriptEnabled:false});
   const foreignPage=await foreign.newPage();await foreignPage.goto(base+'/_preview');
   const foreignToken=await foreignPage.getByTestId('briefing-form').locator('[name="_token"]').inputValue();
   const foreignWrite=await foreign.request.post(ordinary.url,{form:{...revocationPayload,_token:foreignToken}});
   const foreignRead=await foreign.request.get(terminal.url+'?compare_from=2147483646&compare_to=2147483647');
   check([403,404].includes(foreignWrite.status())&&[403,404].includes(foreignRead.status())&&
     !(await foreignRead.text()).includes(terminal.values.headline)&&snapshot()===beforeDenied,
     'a different authenticated company cannot append a currently eligible high head or read the proven terminal comparison');

   editor=await ctx('editor',{javaScriptEnabled:false});
   const editorPage=await editor.newPage();await editorPage.goto(terminal.url);
   const review=editorPage.locator('form.review[data-review-revision="2147483647"]');
   check(await review.isVisible()&&await review.getAttribute('data-review-digest')===lastHtml.digest&&
     await editorPage.getByTestId('revision-limit-notice').isVisible()&&await editorPage.getByTestId('briefing-form').count()===0,
     'an independent reviewer retains an authorized terminal review form while generation stays unavailable');
   await review.locator('[name="decision"]').selectOption('approved_internal');
   await review.locator('[name="note"]').fill('Parecer HTTP sintético no limite.');
   const reviewUrl=new URL(await review.getAttribute('action'),base).toString();
   const [reviewed,reviewLoaded]=await Promise.all([
     editorPage.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='POST'&&r.url()===reviewUrl),
     editorPage.waitForNavigation({waitUntil:'load'}),review.getByRole('button',{name:'Registrar revisão editorial da versão 2147483647',exact:true}).click(),
   ]);
   const reviewRow=editorPage.getByTestId('editorial-review-history').locator('article[data-review-revision="2147483647"][data-review-digest="'+lastHtml.digest+'"]');
   check(reviewed.status()===302&&reviewLoaded.status()===200&&await reviewRow.isVisible()&&
     (await reviewRow.textContent()).includes('Aprovada internamente')&&
     (await reviewRow.textContent()).includes('Parecer HTTP sintético no limite.'),
     'terminal editorial POST succeeds with the exact version and digest and becomes readable history');
   const facts=JSON.parse(sql("SELECT jsonb_agg(jsonb_build_object('project',p.id,'revisions',(SELECT jsonb_agg(v.revision ORDER BY v.revision) FROM sites_preview.versions v WHERE v.project_id=p.id),"+
     "'reviews',(SELECT count(*) FROM sites_preview.reviews r WHERE r.project_id=p.id),'audit',(SELECT count(*) FROM sites_preview.audit a WHERE a.project_id=p.id)) ORDER BY p.product DESC)::text "+
     "FROM sites_preview.projects p WHERE p.id IN ('"+ordinary.id+"'::uuid,'"+terminal.id+"'::uuid)"));
   const own=facts.find(item=>item.project===ordinary.id),lastFacts=facts.find(item=>item.project===terminal.id);
   check(JSON.stringify(own.revisions)==='[1,10000,10001,10002]'&&own.reviews===0&&own.audit===5&&
     JSON.stringify(lastFacts.revisions)==='[1,2147483646,2147483647]'&&lastFacts.reviews===1&&lastFacts.audit===5&&
     snapshot(cases.map(item=>item.id))===initial,
     'the entire journey adds only two sparse histories and one terminal review; every pre-existing row and principal remains exact');
   complete=true;
 } finally {
   await page.close();
   if(editor)await editor.close();if(foreign)await foreign.close();
   if(!complete)await context.close();
 }
 return {verifyRevocation:async()=>{
   try {
     const before=snapshot();
     const reads=await Promise.all([
       context.request.get(terminalUrl),context.request.get(terminalUrl+'/versions/2147483647/html'),
       context.request.get(terminalUrl+'?compare_from=2147483646&compare_to=2147483647'),
     ]);
     check(reads.every(response=>response.status()===403),'revocation denies the same previously readable terminal page, HTML and comparison');
     const write=await context.request.post(appendableUrl,{form:revocationPayload});
     check(write.status()===403&&snapshot()===before,'revocation also denies a valid high CAS with the retained real session/CSRF and no persisted change');
     console.log('REVISION CEILING HTTP PASS: '+newChecks+' new checks; '+baselineChecks+' existing checks retained.');
   } finally {await context.close();}
 }};
};
