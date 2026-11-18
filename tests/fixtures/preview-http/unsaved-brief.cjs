const crypto=require('crypto');

// Runs before the parent's successful POST creates revision62. These submissions
// are validation errors or a stale CAS, so the five persisted tables stay exact.
module.exports=async({ctx,base,dir,one,historyUrl,docker,ok})=>{
 const baselineChecks=340;
 let additionalChecks=0;
 const check=(value,label)=>{ok(value,'unsaved brief: '+label);additionalChecks++;};
 const snapshot=()=>{
   const tables=['principals','projects','versions','reviews','audit'];
   const query=tables.map(table=>
     "SELECT '"+table+"' AS table_name, count(*) AS row_count, "+
     "encode(sha256(convert_to(COALESCE(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text,'[]'),'UTF8')),'hex') AS digest "+
     'FROM sites_preview.'+table+' r').join(' UNION ALL ')+' ORDER BY table_name';
   return docker(['exec','-T','db','psql','-U','postgres','-d','sites_preview_http_ci',
     '-X','-v','ON_ERROR_STOP=1','-Atc',query]).trim();
 };
 const sorted=entries=>JSON.stringify([...entries].sort(([left],[right])=>left.localeCompare(right)));
 const hasQuery=(url,expected)=>sorted(new URL(url,base).searchParams)===sorted(Object.entries(expected));
 const withQuery=(url,values)=>url+'?'+new URLSearchParams(values);
 const context=await ctx('author',{javaScriptEnabled:false});
 context.setDefaultTimeout(20000);
 context.setDefaultNavigationTimeout(20000);
 const page=await context.newPage();
 let reviewerContext,foreignContext,retryUrl,retryData,finished=false,navigationRequests=0;
 page.on('request',request=>{if(request.isNavigationRequest())navigationRequests++;});
 const form=()=>page.getByTestId('briefing-form');
 const notice=()=>page.getByTestId('unsaved-brief-notice');
 const saved=()=>page.getByTestId('saved-current-brief');
 const names=['brand','headline','description','call_to_action','benefits'];
 const readAttempt=async()=>{
   const values={productCode:await form().locator('#product').inputValue()};
   for(const name of names)values[name]=await form().locator('[name="'+name+'"]').inputValue();
   return values;
 };
 const fill=async values=>{for(const name of names)await form().locator('[name="'+name+'"]').fill(values[name]);};
 const savedValues=async()=>{
   const values={};
   for(const field of ['productCode','brand','headline','description','callToAction']){
     values[field]=await saved().locator('dd[data-field="'+field+'"]').textContent();
   }
   values.benefits=await saved().locator('dd[data-field="benefits"] ol li').allTextContents();
   return values;
 };
 const savedExpected=attempt=>({productCode:attempt.productCode==='onepage'?'Site Onepage':'Landing Page',
   brand:attempt.brand,headline:attempt.headline,description:attempt.description,
   callToAction:attempt.call_to_action,benefits:attempt.benefits.split('\n')});
 const same=(left,right)=>JSON.stringify(left)===JSON.stringify(right);
 const submit=async()=>{
   const target=new URL(await form().getAttribute('action'),base).toString();
   const [response]=await Promise.all([
     page.waitForResponse(response=>response.request().isNavigationRequest()&&response.request().method()==='POST'&&response.url()===target),
     page.waitForNavigation({waitUntil:'load'}),
     form().locator('button[type="submit"]').click(),
   ]);
   return response;
 };
 const identified=async(revision,label)=>{
   check(await notice().isVisible()&&(await notice().textContent()).startsWith('Este briefing ainda não foi salvo.')&&
     await page.getByRole('heading',{name:'Briefing ainda não salvo',exact:true}).isVisible()&&
     await form().getAttribute('aria-describedby')==='unsaved-brief-notice'&&
     await page.getByText('Este briefing corresponde à versão atual '+revision+'.',{exact:true}).count()===0,
     label+' explicitly identifies the attempt as unsaved and associates the notice with the form');
 };
 const savedSnapshot=async(revision,digest,expected,label)=>{
   check(await saved().isVisible()&&await saved().getAttribute('open')===null&&
     await saved().getAttribute('data-brief-revision')===String(revision)&&
     await saved().getAttribute('data-brief-digest')===digest&&
     await saved().locator('summary').textContent()==='Consultar briefing salvo da versão '+revision&&
     await saved().locator('code').textContent()==='SHA-256: '+digest&&
     await saved().locator('dd[data-field]').count()===6&&same(await savedValues(),savedExpected(expected))&&
     !(await saved().locator('dl').isVisible()),
     label+' keeps a closed native snapshot with all six exact saved fields, revision and immutable HTML digest');
 };
 const openSaved=async(attempt,label)=>{
   const beforeNavigation=navigationRequests,beforeUrl=page.url();
   await saved().locator('summary').click();
   await saved().locator('dl').waitFor({state:'visible'});
   check(await saved().getAttribute('open')!==null&&navigationRequests===beforeNavigation&&page.url()===beforeUrl&&
     same(await readAttempt(),attempt),
     label+' opens native details without JavaScript, another navigation request or replacement of any attempted field');
 };
 const before=snapshot();
 try {
   const oneNavigation={status:'approved_internal',format:'onepage',q:'Empresa'};
   const oneUrl=withQuery(one,oneNavigation);
   const initial=await page.goto(oneUrl);
   const original=await readAttempt();
   check(initial.status()===200&&await form().locator('[name="expected_revision"]').inputValue()==='2'&&
     await notice().count()===0&&await saved().count()===0&&await form().getAttribute('aria-describedby')===null,
     'normal GET starts at the persisted revision2 without an unsaved notice or saved-snapshot disclosure');
   const bytes=await context.request.get(one+'/versions/2/html');
   const digest=crypto.createHash('sha256').update(await bytes.body()).digest('hex');
   check(bytes.status()===200&&await page.locator('[data-version="2"] code').first().textContent()==='SHA-256: '+digest,
     'the revision2 snapshot oracle uses the immutable bytes served by the real private HTML endpoint');
   const attempted={productCode:'onepage',brand:'Tentativa <img data-unsaved-attempt-probe="literal" src=x>',
     headline:'T'.repeat(181),description:'Descrição ainda não salva com https://exemplo.invalid/ e onboarding=aberto.',
     call_to_action:'Leia a tentativa',benefits:'Benefício tentado <b data-unsaved-benefit="literal">sem formato</b>\nOutro benefício ainda não salvo'};
   await fill(attempted);
   const invalid=await submit();
   retryUrl=invalid.url();retryData=invalid.request().postData();
   check(invalid.status()===422&&await page.locator('#error-headline').isVisible()&&same(await readAttempt(),attempted)&&
     await form().locator('[name="expected_revision"]').inputValue()==='2'&&hasQuery(page.url(),oneNavigation),
     'the real invalid POST preserves every attempted field and its navigation while keeping the current CAS2');
   await identified(2,'validation422');
   await savedSnapshot(2,digest,original,'validation422');
   await openSaved(attempted,'validation422');
   check(await page.locator('[data-unsaved-attempt-probe],[data-unsaved-benefit],[data-comparison-probe]').count()===0&&
     (await savedValues()).benefits.some(value=>value.includes('<strong data-comparison-probe="literal">')),
     'both attempted markup and the stored literal markup remain text rather than becoming DOM elements');
   await form().locator('[name="headline"]').fill(original.headline);
   check(same(await readAttempt(),{...attempted,headline:original.headline})&&
     same(await savedValues(),savedExpected(original))&&await notice().isVisible(),
     'explicitly copying one saved value into the form leaves the other attempted fields and the saved snapshot unchanged');

   // Emulate the sole hidden CAS retained by an older tab. Every visible field
   // is filled normally; the real form then performs a POST navigation and
   // receives the server's409 document and headers with application JS disabled.
   const staleAttempt={...attempted,brand:'Tentativa válida da outra aba',headline:'Chamada ainda não salva da aba anterior'};
   await fill(staleAttempt);
   const stalePayload={_token:await form().locator('[name="_token"]').inputValue(),product:'onepage',
     expected_revision:'1',...Object.fromEntries(names.map(name=>[name,staleAttempt[name]]))};
   await form().locator('[name="expected_revision"]').evaluate(input=>{input.value='1';});
   const conflict=await submit();
   check(conflict.status()===409&&await page.getByText(/Outra versão foi criada enquanto você editava/).isVisible()&&
     same(await readAttempt(),staleAttempt)&&await form().locator('[name="expected_revision"]').inputValue()==='2',
     'the stale-CAS POST returns409, retains the separate attempt and preserves the existing refresh to current CAS2');
   await identified(2,'conflict409');
   await savedSnapshot(2,digest,original,'conflict409');
   await openSaved(staleAttempt,'conflict409');
   for(const [name,size] of [['desktop',{width:1440,height:1000}],['mobile',{width:390,height:844}]]){
     await page.setViewportSize(size);
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
       'the unsaved attempt and expanded saved snapshot fit the '+name+' viewport');
     await form().locator('..').screenshot({path:dir+'/unsaved-brief-'+name+'.png'});
   }
   const fresh=await page.goto(oneUrl);
   check(fresh.status()===200&&same(await readAttempt(),original)&&await notice().count()===0&&await saved().count()===0,
     'a fresh GET shows the saved briefing without reusing either failed attempt or its notice');

   const queueNavigation={status:'awaiting_review',format:'onepage',q:'CI'};
   const queueResponse=await page.goto(withQuery(base+'/_preview',queueNavigation));
   const next=new URL(await page.getByRole('link',{name:'Próximos projetos'}).getAttribute('href'),base);
   const navigation={...queueNavigation,cursor:next.searchParams.get('cursor')};
   check(queueResponse.status()===200&&navigation.cursor&&hasQuery(next.toString(),navigation),
     'the history case obtains its cursor from the actual filtered queue');
   const historyNavigation={...navigation,before_revision:'41'};
   const historicalUrl=withQuery(historyUrl,historyNavigation);
   const historical=await page.goto(historicalUrl);
   const head=await readAttempt();
   const headBytes=await context.request.get(historyUrl+'/versions/61/html');
   const headDigest=crypto.createHash('sha256').update(await headBytes.body()).digest('hex');
   const window=()=>page.locator('[data-version]').evaluateAll(nodes=>nodes.map(node=>Number(node.getAttribute('data-version'))));
   check(historical.status()===200&&headBytes.status()===200&&
     await form().locator('[name="expected_revision"]').inputValue()==='61'&&
     head.headline==='Histórico atual após consulta anterior'&&same(await window(),Array.from({length:20},(_,index)=>40-index))&&
     await page.locator('[data-version="61"]').count()===0,
     'the historical page displays40..21 while its persisted briefing source and CAS are the actual head61');
   const historyAttempt={...attempted,brand:'Tentativa enquanto consultava o histórico',description:'Texto ainda não salvo, separado do head61.'};
   await fill(historyAttempt);
   const historyInvalid=await submit();
   check(historyInvalid.status()===422&&await page.locator('#error-headline').isVisible()&&
     same(await readAttempt(),historyAttempt)&&hasQuery(page.url(),historyNavigation)&&
     same(await window(),Array.from({length:20},(_,index)=>40-index))&&await form().locator('[name="expected_revision"]').inputValue()==='61',
     'the historical invalid POST preserves its attempt, queue filters, real cursor, history window and current CAS61');
   await identified(61,'historical validation422');
   await savedSnapshot(61,headDigest,head,'historical validation422');
   await openSaved(historyAttempt,'historical validation422');
   const postFields=new URLSearchParams(historyInvalid.request().postData());
   const back=await page.getByRole('link',{name:'Voltar à fila'}).getAttribute('href');
   const older=await page.getByRole('link',{name:'Versões anteriores'}).getAttribute('href');
   check(hasQuery(await form().getAttribute('action'),historyNavigation)&&hasQuery(back,navigation)&&
     hasQuery(older,{...navigation,before_revision:'21'})&&
     !['status','format','q','cursor','before_revision'].some(name=>postFields.has(name)),
     'navigation remains in explicit local URLs and does not become mutable briefing fields');

   const createNavigation={status:'awaiting_review',format:'landing_page',q:'fictício'};
   await page.goto(withQuery(base+'/_preview',createNavigation));
   await form().locator('#product').selectOption('landing_page');
   const createAttempt={...attempted,productCode:'landing_page'};
   await fill(createAttempt);
   const creation=await submit();
   check(creation.status()===422&&await page.locator('#error-headline').isVisible()&&
     same(await readAttempt(),createAttempt)&&await notice().isVisible()&&await saved().count()===0&&
     await form().locator('[name="expected_revision"]').count()===0&&hasQuery(page.url(),createNavigation),
     'an invalid new project is identified as unsaved without inventing a saved briefing or revision');
   await page.goto(withQuery(base+'/_preview',createNavigation));
   check(await form().locator('[name="brand"]').inputValue()===''&&await form().locator('[name="headline"]').inputValue()===''&&
     await notice().count()===0&&await saved().count()===0,
     'a fresh creation GET does not retain the failed briefing or its notice');

   reviewerContext=await ctx('editor',{javaScriptEnabled:false});
   const reviewer=await reviewerContext.newPage();
   const reviewerGet=await reviewer.goto(historyUrl);
   const review=()=>reviewer.locator('form.review[data-review-revision="61"]');
   check(reviewerGet.status()===200&&await review().isVisible()&&await reviewer.getByTestId('briefing-form').count()===0&&
     await reviewer.getByTestId('unsaved-brief-notice').count()===0&&await reviewer.getByTestId('saved-current-brief').count()===0,
     'a reviewer GET keeps its ordinary read-only briefing without an unsaved-author notice');
   const reviewUrl=new URL(await review().getAttribute('action'),base).toString();
   const longNote='N'.repeat(2001);
   await review().locator('[name="decision"]').selectOption('changes_requested');
   await review().locator('[name="note"]').fill(longNote);
   const [reviewError]=await Promise.all([
     reviewer.waitForResponse(response=>response.request().isNavigationRequest()&&response.request().method()==='POST'&&response.url()===reviewUrl),
     reviewer.waitForNavigation({waitUntil:'load'}),
     review().getByRole('button',{name:'Registrar revisão editorial',exact:true}).click(),
   ]);
   check(reviewError.status()===422&&await reviewer.locator('#error-note-61').isVisible()&&
     await review().locator('[name="note"]').inputValue()===longNote&&
     await reviewer.getByTestId('unsaved-brief-notice').count()===0&&await reviewer.getByTestId('saved-current-brief').count()===0,
     'an actual invalid editorial review retains its own guidance without being mislabeled as an unsaved briefing');

   foreignContext=await ctx('other',{javaScriptEnabled:false});
   const foreign=await foreignContext.newPage();
   await foreign.goto(base+'/_preview');
   const foreignToken=await foreign.getByTestId('briefing-form').locator('[name="_token"]').inputValue();
   const denied=await foreignContext.request.post(oneUrl,{form:{...stalePayload,_token:foreignToken},maxRedirects:0});
   const deniedText=await denied.text();
   check([403,404].includes(denied.status())&&!deniedText.includes('unsaved-brief-notice')&&
     !deniedText.includes('saved-current-brief')&&!deniedText.includes(original.headline)&&!deniedText.includes(staleAttempt.headline),
     'another company with a valid CSRF cannot obtain the known project or a recovered saved snapshot through POST');
   check(snapshot()===before,
     'all projects, immutable brief/HTML/digests, reviews, audit and principal rows remain unchanged across every failed submission and native disclosure');
   finished=true;
 } finally {
   await page.close();
   if(reviewerContext)await reviewerContext.close();
   if(foreignContext)await foreignContext.close();
   // Keep only this authenticated session for the exact failed-POST replay
   // after the parent revokes the author and restarts the application.
   if(!finished)await context.close();
 }
 return {
   async verifyRevocation(){
     try {
       const revokedBefore=snapshot();
       const response=await context.request.post(retryUrl,{data:retryData,
         headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
       const text=await response.text();
       check(response.status()===403&&!text.includes('unsaved-brief-notice')&&!text.includes('saved-current-brief')&&
         !text.includes('Segunda versão demonstrativa')&&!text.includes('Tentativa'),
         'revocation denies the exact previously recoverable POST without disclosing either the attempted or saved briefing');
       check(snapshot()===revokedBefore,'the refused POST leaves the deliberately revoked persisted state unchanged');
       console.log('UNSAVED BRIEF HTTP PASS: '+additionalChecks+' new checks; '+baselineChecks+' existing checks retained.');
     } finally { await context.close(); }
   },
 };
};
