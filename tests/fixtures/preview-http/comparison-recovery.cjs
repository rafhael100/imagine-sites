// Read journeys run before the existing edit appends revision62. The only POST
// below is a real, invalid briefing submission, so every persisted row must stay
// unchanged. Database access uses the parent's already verified disposable CI.
module.exports=async({ctx,a,e,o,base,dir,historyUrl,docker,ok})=>{
 const baselineChecks=273;
 let additionalChecks=0;
 const check=(value,label)=>{ok(value,'comparison recovery: '+label);additionalChecks++;};
 const snapshot=()=>{
   const tables=['principals','projects','versions','reviews','audit'];
   const query=tables.map(table=>
     "SELECT '"+table+"' AS table_name, count(*) AS row_count, "+
     "encode(sha256(convert_to(COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text,'[]'),'UTF8')),'hex') AS digest "+
     'FROM sites_preview.'+table+' r').join(' UNION ALL ')+' ORDER BY table_name';
   return docker(['exec','-T','db','psql','-U','postgres','-d','sites_preview_http_ci',
     '-X','-v','ON_ERROR_STOP=1','-Atc',query]).trim();
 };
 const sorted=entries=>JSON.stringify([...entries].sort(([a],[b])=>a.localeCompare(b)));
 const hasQuery=(url,expected)=>sorted(new URL(url,base).searchParams)===sorted(Object.entries(expected));
 const projectUrl=(project,values)=>project+'?'+new URLSearchParams(values);
 const before=snapshot();
 const context=await ctx('author',{javaScriptEnabled:false});
 context.setDefaultTimeout(20000);
 context.setDefaultNavigationTimeout(20000);
 const page=await context.newPage();
 const reviewer=await e.newPage();
 const form=()=>page.getByTestId('comparison-form');
 const error=()=>page.getByTestId('comparison-error');
 const submitPair=async(from,to)=>{
   await form().getByLabel('Versão anterior',{exact:true}).fill(from);
   await form().getByLabel('Versão posterior',{exact:true}).fill(to);
   const pathname=new URL(page.url()).pathname;
   const [response]=await Promise.all([
     page.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='GET'&&
       new URL(r.url()).pathname===pathname&&new URL(r.url()).searchParams.get('compare_from')===from&&
       new URL(r.url()).searchParams.get('compare_to')===to),
     page.waitForNavigation({waitUntil:'load'}),
     form().getByRole('button',{name:'Comparar versões',exact:true}).click(),
   ]);
   return response;
 };
 const recovered=async(response,from,to,label)=>{
   check(response.status()===422&&await form().isVisible()&&await error().isVisible()&&
     await error().getAttribute('role')==='alert'&&
     await form().getByLabel('Versão anterior',{exact:true}).inputValue()===from&&
     await form().getByLabel('Versão posterior',{exact:true}).inputValue()===to,
     label+' returns422 with the exact submitted values and a visible inline alert');
   const errorId=await error().getAttribute('id');
   const inputs=await form().locator('input[name=compare_from],input[name=compare_to]').evaluateAll(nodes=>
     nodes.map(node=>({invalid:node.getAttribute('aria-invalid'),describedBy:node.getAttribute('aria-describedby')})));
   check(errorId&&inputs.length===2&&inputs.every(input=>input.invalid==='true'&&
     (input.describedBy||'').split(/\s+/).includes(errorId))&&await page.getByTestId('editorial-comparison').count()===0,
     label+' associates both fields with the error and displays no invalid comparison');
 };
 const retainedContext=async(expected,from,to,label)=>{
   const hidden=await form().locator('input[type=hidden]').evaluateAll(nodes=>nodes.map(node=>[node.name,node.value]));
   check(hasQuery(page.url(),{...expected,compare_from:from,compare_to:to})&&
     sorted(hidden)===sorted(Object.entries(expected)),label+' retains only the exact safe context in the URL and GET form');
 };
 const headAndWindow=async(label)=>{
   const revisions=await page.locator('[data-version]').evaluateAll(nodes=>nodes.map(node=>Number(node.getAttribute('data-version'))));
   check(JSON.stringify(revisions)===JSON.stringify(Array.from({length:20},(_,index)=>40-index))&&
     await page.locator('input[name=expected_revision]').inputValue()==='61'&&
     await page.getByLabel('Chamada principal',{exact:true}).inputValue()==='Histórico atual após consulta anterior',
     label+' keeps history40..21 while the briefing and CAS remain at the actual head61');
 };
 const corrected=async(response,label)=>{
   const panel=page.getByTestId('editorial-comparison');
   check(response.status()===200&&await error().count()===0&&await panel.isVisible()&&
     (await panel.locator('[data-compare-side=from][data-compare-revision="1"] [data-field=headline]').textContent())==='Histórico sintético versão 1'&&
     (await panel.locator('[data-compare-side=to][data-compare-revision="60"] [data-field=headline]').textContent())==='Histórico sintético versão 60',
     label+' corrects the actual no-JavaScript form to200 and the requested immutable versions');
 };
 let equalUrl;
 try {
   // Obtain a genuine cursor under the same search and filters. A project URL
   // retains queue navigation independently of the current queue page contents.
   const queueContext={status:'awaiting_review',format:'onepage',q:'CI'};
   const queueResponse=await page.goto(projectUrl(base+'/_preview',queueContext));
   const next=new URL(await page.getByRole('link',{name:'Próximos projetos'}).getAttribute('href'),base);
   const navigation={...queueContext,cursor:next.searchParams.get('cursor')};
   check(queueResponse.status()===200&&navigation.cursor&&hasQuery(next.toString(),navigation),
     'the cursor comes from a successful searched and filtered queue');
   const older={...navigation,before_revision:'41'};
   check((await page.goto(projectUrl(historyUrl,older))).status()===200&&await form().isVisible(),
     'the paginated project opens with a usable comparison form');
   await recovered(await submitPair('1','1'),'1','1','equal revisions');
   equalUrl=page.url();
   await retainedContext(older,'1','1','equal revisions');
   await headAndWindow('equal revisions');
   const back=new URL(await page.getByRole('link',{name:'Voltar à fila'}).getAttribute('href'),base);
   const clear=new URL(await page.getByRole('link',{name:'Limpar comparação',exact:true}).getAttribute('href'),base);
   check(back.pathname==='/_preview'&&hasQuery(back.toString(),navigation)&&
     clear.pathname===new URL(historyUrl).pathname&&hasQuery(clear.toString(),older),
     'back and clear links preserve queue context; clear also preserves the history window');
   for(const [name,size] of [['desktop',{width:1440,height:1000}],['mobile',{width:390,height:844}]]){
     await page.setViewportSize(size);
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
       'the inline error and retained fields fit the '+name+' viewport');
     await page.locator('section.comparison').screenshot({path:dir+'/comparison-recovery-'+name+'.png'});
   }

   // Submit from the recovery page, through its real edit form. A known invalid
   // headline prevents a new revision while proving the actual serialized POST
   // excludes GET-only selectors and still reaches ordinary field validation.
   const edit=page.locator('form:has(input[name=expected_revision])');
   const editUrl=new URL(await edit.getAttribute('action'),base);
   const tooLong='C'.repeat(181);
   await page.getByLabel('Chamada principal',{exact:true}).fill(tooLong);
   const [post,postPage]=await Promise.all([
     page.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='POST'&&r.url()===editUrl.toString()),
     page.waitForNavigation({waitUntil:'load'}),
     edit.getByRole('button',{name:'Gerar nova versão privada →',exact:true}).click(),
   ]);
   const body=new URLSearchParams(post.request().postData());
   check(post.status()===422&&postPage!==null&&postPage.status()===422&&
     await page.locator('#error-headline').isVisible()&&
     await page.getByLabel('Chamada principal',{exact:true}).inputValue()===tooLong&&
     await page.locator('input[name=expected_revision]').inputValue()==='61',
     'editing from a comparison error still reaches ordinary briefing validation without a new revision');
   check(![...editUrl.searchParams.keys(),...body.keys()].some(key=>key.startsWith('compare_'))&&
     body.get('expected_revision')==='61'&&body.get('headline')===tooLong,
     'the real edit POST contains the current briefing and no comparison selectors in its URL or body');
   await page.goto(equalUrl);
   await corrected(await submitPair('1','60'),'equal-pair recovery');
   await retainedContext(older,'1','60','corrected equal pair');
   await headAndWindow('corrected equal pair');
   await page.setViewportSize({width:1440,height:1000});
   await page.getByTestId('editorial-comparison').screenshot({path:dir+'/comparison-recovery-corrected.png'});

   const newer={...navigation,after_revision:'20'};
   await page.goto(projectUrl(historyUrl,newer));
   await recovered(await submitPair('60','1'),'60','1','reversed revisions');
   await retainedContext(newer,'60','1','reversed revisions');
   await headAndWindow('reversed revisions');
   await corrected(await submitPair('1','60'),'reversed-pair recovery');
   await retainedContext(newer,'1','60','corrected reversed pair');
   await headAndWindow('corrected reversed pair');

   for(const [parameters,from,to,label] of [
     [{compare_from:'1'},'1','','partial pair'],
     [{compare_from:'',compare_to:'60'},'','60','explicit empty field'],
   ]){
     await recovered(await page.goto(projectUrl(historyUrl,{...older,...parameters})),from,to,label);
   }
   const single=base+'/_preview/projects/50000000-0000-4000-8000-000000000001';
   await recovered(await page.goto(projectUrl(single,{compare_from:'1',compare_to:'1'})),'1','1','single-version project');
   const [cleared]=await Promise.all([
     page.waitForNavigation({waitUntil:'load'}),
     page.getByRole('link',{name:'Limpar comparação',exact:true}).click(),
   ]);
   check(cleared!==null&&cleared.status()===200&&page.url()===single&&await error().count()===0&&
     await page.locator('input[name=expected_revision]').inputValue()==='1'&&await page.locator('[data-version]').count()===1,
     'clearing a single-version error returns to the unchanged project without comparison selectors');

   const reviewResponse=await reviewer.goto(projectUrl(historyUrl,{...navigation,compare_from:'1',compare_to:'1'}));
   const review=reviewer.locator('form.review[data-review-revision="61"]');
   const reviewUrl=new URL(await review.getAttribute('action'),base);
   const reviewFields=await review.locator('[name]').evaluateAll(nodes=>nodes.map(node=>node.name));
   check(reviewResponse.status()===422&&await reviewer.getByTestId('comparison-error').isVisible()&&
     reviewUrl.pathname===new URL(historyUrl).pathname+'/versions/61/review'&&hasQuery(reviewUrl.toString(),navigation)&&
     !reviewFields.some(name=>name.startsWith('compare_')),
     'the reviewer recovery page keeps its review action at head61 and excludes comparison fields');

   for(const query of [
     'compare_from[]=1&compare_to=2',
     'compare_from=1&compare_to[]=2',
     'compare_from=1&compare_to=1&unknown=1',
     'compare_from=1&compare_to=1&company_id=bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
     'compare_from=1&compare_to=1&cursor=invalid',
   ]){
     const response=await a.request.get(historyUrl+'?'+query),text=await response.text();
     check(response.status()===422&&!text.includes('comparison-form')&&!text.includes('comparison-error')&&
       !text.includes('Histórico atual após consulta anterior'),
       'unsafe comparison or navigation is a hard denial, not a recovered workspace: '+query);
   }
   const absent=await a.request.get(projectUrl(historyUrl,{...older,compare_from:'1',compare_to:'9999'}));
   const absentText=await absent.text();
   check(absent.status()===404&&!absentText.includes('comparison-form')&&!absentText.includes('Histórico sintético versão'),
     'a valid increasing pair with an absent revision keeps the existing404');
   const foreign=await o.request.get(equalUrl),foreignText=await foreign.text();
   check([403,404].includes(foreign.status())&&!foreignText.includes('comparison-form')&&
     !foreignText.includes('comparison-error')&&!foreignText.includes('Histórico sintético versão')&&
     !foreignText.includes('Histórico atual após consulta anterior'),
     'another company cannot use recovery to read the known project or its current briefing');
   check(snapshot()===before,
     'all projects, immutable versions/HTML/digests, reviews, audit and principals are byte-equivalent after recovery and the rejected POST');
 } finally {
   await reviewer.close();
   await context.close();
 }
 return {
   async verifyRevocation(){
     // The parent has now revoked the author. Compare against that intentional
     // state change, not against the earlier active-principal snapshot.
     const revokedBefore=snapshot();
     const response=await a.request.get(equalUrl),text=await response.text();
     check(response.status()===403&&!text.includes('comparison-form')&&!text.includes('comparison-error')&&
       !text.includes('Histórico sintético versão')&&!text.includes('Histórico atual após consulta anterior'),
       'revocation denies the same invalid pair that previously rendered the recovery page');
     check(snapshot()===revokedBefore,'the refused recovery request leaves the revoked persisted state unchanged');
     console.log('COMPARISON RECOVERY HTTP PASS: '+additionalChecks+' new checks; '+baselineChecks+' existing checks retained.');
   },
 };
};
