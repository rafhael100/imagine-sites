const {chromium}=require('playwright');const fs=require('fs'),cp=require('child_process'),crypto=require('crypto'),path=require('path');
(async()=>{
 const vault=JSON.parse(fs.readFileSync(process.env.PREVIEW_CI_MATERIAL+'/browser.json','utf8'));
 const dir=process.env.PREVIEW_HTTP_EVIDENCE;fs.mkdirSync(dir,{recursive:true});let checks=0;
 const ok=(v,m)=>{if(!v)throw Error(m);checks++;};
 const compose=path.join(process.env.GITHUB_WORKSPACE,'tests/fixtures/preview-http/compose.yaml');
 const docker=(args)=>cp.execFileSync('docker',['compose','-f',compose,...args],{stdio:['ignore','pipe','pipe'],env:process.env}).toString();
 const b=await chromium.launch({headless:true});
 // Only disposable one-run certificates in this CI browser. curl --cacert independently verifies HTTPS before this script.
 const ctx=async(name,options={})=>b.newContext({ignoreHTTPSErrors:true,httpCredentials:name?{username:name,password:vault[name]}:undefined,...options});
 const a=await ctx('author'),e=await ctx('editor'),o=await ctx('other'),anon=await ctx();
 const base='https://127.0.0.1:18496';
 ok((await anon.request.get(base+'/_preview')).status()===401,'anonymous denied');
 ok((await anon.request.get(base+'/_preview',{headers:{'Remote-User':'author','X-Imagine-Preview-Authenticated':'nginx-basic-v1'}})).status()===401,'header impersonation denied');
 const p=await a.newPage();await p.goto(base+'/_preview');ok(await p.getByText('DEMONSTRAÇÃO PRIVADA',{exact:true}).isVisible(),'demo label');
 const historyId='60000000-0000-4000-8000-000000000001';
 const historyUrl=base+'/_preview/projects/'+historyId;
 const firstQueue=await p.locator('[data-project]').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('data-project')));
 ok(firstQueue.length===50,'queue shows one bounded page from56 synthetic projects');
 await p.setViewportSize({width:1440,height:1000});await p.screenshot({path:dir+'/queue-desktop.png'});
 await p.setViewportSize({width:390,height:844});await p.getByRole('heading',{name:'Fila de revisão editorial',exact:true}).scrollIntoViewIfNeeded();await p.screenshot({path:dir+'/queue-mobile.png'});
 ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'paginated queue fits mobile viewport');
 await p.setViewportSize({width:1280,height:720});
 ok(await p.getByText('Histórico sintético versão 60',{exact:true}).isVisible(),'queue identifies project by current brief headline');
 await p.getByRole('link',{name:'Próximos projetos'}).click();
 const secondQueueUrl=p.url();
 const secondQueue=await p.locator('[data-project]').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('data-project')));
 ok(secondQueue.length===6&&new Set([...firstQueue,...secondQueue]).size===56,'second queue page exposes every older project without overlap');
 ok(secondQueue.includes('50000000-0000-4000-8000-000000000001'),'oldest project remains reachable beyond50');
 ok(await p.getByRole('link',{name:'Voltar ao início da fila'}).isVisible(),'queue provides return to first page');
 const otherQueue=await o.request.get(secondQueueUrl);
 ok(otherQueue.status()===200&&!(await otherQueue.text()).includes('Navegação CI'),'cursor does not authorize another company');
 ok((await a.request.get(base+'/_preview?company_id=bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')).status()===422,'GET company injection rejected');
 ok((await a.request.get(base+'/_preview?cursor=not-a-valid-cursor')).status()===422,'malformed cursor rejected before queue read');
 await p.getByLabel('Parecer na fila').selectOption('approved_internal');
 await p.getByLabel('Formato da fila').selectOption('onepage');
 await p.getByRole('button',{name:'Aplicar filtros'}).click();
 ok(await p.locator('[data-project]').count()===1&&await p.locator('[data-project="'+historyId+'"]').isVisible(),'status and product filters select the exact current reviewed version');
 await p.getByRole('link',{name:'Histórico CI',exact:true}).click();
 ok(new URL(p.url()).searchParams.get('status')==='approved_internal','opening project preserves queue filter context');
 await p.getByRole('link',{name:'Voltar à fila'}).click();
 ok(await p.getByLabel('Parecer na fila').inputValue()==='approved_internal'&&await p.getByLabel('Formato da fila').inputValue()==='onepage','return to queue retains selected filters');
 await p.goto(base+'/_preview');
 const fill=async(product,title)=>{await p.getByLabel('Formato',{exact:true}).selectOption(product);await p.getByLabel('Marca fictícia').fill('Empresa Fictícia CI');await p.getByLabel('Chamada principal').fill(title);await p.getByLabel('Descrição',{exact:true}).fill('Briefing sintético para validar a interface privada.');await p.getByLabel('Chamada do botão').fill('Conheça nossa proposta');await p.getByLabel('Benefícios').fill('Atendimento organizado\nInformações claras');};
 await fill('onepage','Uma empresa preparada para crescer');await p.getByRole('button',{name:'Criar demonstração'}).click();await p.waitForURL('**/_preview/projects/*');const one=p.url();
 ok(await p.getByText('Versão 1',{exact:true}).isVisible(),'project created by real Laravel HTTP');
 ok(await p.getByLabel('Formato',{exact:true}).isDisabled(),'existing product is immutable in form');
 ok(await p.getByRole('button',{name:'Registrar revisão editorial'}).count()===0,'author is not offered own-version review');
 const form=async()=>({
  _token:await p.locator('input[name=_token]').first().getAttribute('value'),product:'onepage',
  brand:await p.getByLabel('Marca fictícia').inputValue(),headline:await p.getByLabel('Chamada principal').inputValue(),
  description:await p.getByLabel('Descrição',{exact:true}).inputValue(),call_to_action:await p.getByLabel('Chamada do botão').inputValue(),
  benefits:await p.getByLabel('Benefícios').inputValue(),expected_revision:'1'
 });
 const original=await form();
 await p.getByLabel('Benefícios').fill(Array(9).fill('Benefício fictício').join('\n'));
 const invalidResponse=p.waitForResponse(r=>r.url()===one&&r.request().method()==='POST');
 await p.getByRole('button',{name:'Gerar nova versão privada'}).click();
 ok((await invalidResponse).status()===422,'invalid briefing is validation error, not authorization failure');
 ok(await p.getByRole('alert').isVisible()&&(await p.getByLabel('Benefícios').inputValue()).split('\n').length===9,'failed briefing stays in response for correction');
 ok(await p.getByLabel('Marca fictícia').inputValue()===original.brand,'other valid briefing fields retained');
 await p.getByLabel('Benefícios').fill(original.benefits);
 const switched=await a.request.post(one,{form:{...original,product:'landing_page'}});
 ok(switched.status()===422&&(await switched.text()).includes('O formato deste projeto não pode ser alterado'),'product tampering gets safe validation feedback');
 let html=(await a.request.get(one+'/versions/1/html'));let body=await html.body();let digest=crypto.createHash('sha256').update(body).digest('hex');ok(html.status()===200&&await p.locator('[data-version="1"]').getByText('SHA-256: '+digest,{exact:true}).isVisible(),'stored exact digest');
 ok(html.headers()['cache-control'].includes('no-store')&&html.headers()['content-security-policy'].includes('sandbox'),'private sandboxed HTML');
 ok((await a.request.post(one)).status()===419,'CSRF missing denied');
 const bad=await a.request.post(one,{form:{_token:await p.locator('input[name=_token]').first().getAttribute('value'),company_id:'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'}});ok(bad.status()===422,'browser company field rejected');
 const revisedBrief={headline:'Segunda versão demonstrativa',description:'Conteúdo revisado para destacar informações claras.',callToAction:'Confira os detalhes',benefits:['Atendimento organizado','Texto literal <strong data-comparison-probe="literal">sem formatação</strong>']};
 await p.getByLabel('Chamada principal').fill(revisedBrief.headline);
 await p.getByLabel('Descrição',{exact:true}).fill(revisedBrief.description);
 await p.getByLabel('Chamada do botão').fill(revisedBrief.callToAction);
 await p.getByLabel('Benefícios').fill(revisedBrief.benefits.join('\n'));
 await p.getByRole('button',{name:'Gerar nova versão privada'}).click();await p.waitForURL(one);
 ok(await p.getByText('Versão 2',{exact:true}).isVisible()&&await p.getByText('Versão 1',{exact:true}).isVisible(),'version history persisted');
 const conflict=await a.request.post(one,{form:{...original,headline:'Briefing de outra aba preservado'}});
 const conflictBody=await conflict.text();
 ok(conflict.status()===409&&conflictBody.includes('Briefing de outra aba preservado')&&conflictBody.includes('Outra versão foi criada'),'concurrent edit returns409 with briefing retained');
 ok((await a.request.get(one+'/versions/3/html')).status()===403,'validation and conflicts created no extra revision');
 ok(crypto.createHash('sha256').update(await (await a.request.get(one+'/versions/1/html')).body()).digest('hex')===digest,'old immutable version');
 const own=await a.request.post(one+'/versions/2/review',{form:{_token:await p.locator('input[name=_token]').first().getAttribute('value'),sha256:(await p.locator('[data-version="2"] code').first().textContent()).replace('SHA-256: ',''),decision:'approved_internal'},headers:{'Remote-User':'editor'}});ok(own.status()===403,'own editorial review denied and identity header ignored');
 const ep=await e.newPage();await ep.goto(base+'/_preview');
 const projectId=new URL(one).pathname.split('/').pop();
 ok(await ep.locator('[data-project="'+projectId+'"]').getByText('Aguardando revisão',{exact:true}).isVisible(),'queue shows current revision awaiting editorial review');
 ok(await ep.getByRole('button',{name:'Criar demonstração'}).count()===0,'reviewer sees queue without author-only form');
 await ep.goto(one);ok(await ep.getByRole('button',{name:'Gerar nova versão privada'}).count()===0,'reviewer sees briefing read-only');await ep.getByLabel('Parecer editorial desta versão').first().selectOption('approved_internal');await ep.getByRole('button',{name:'Registrar revisão editorial'}).first().click();await ep.waitForURL(one);ok(await ep.getByText(/Versão 2: Aprovada internamente/).isVisible(),'different named editorial reviewer');
 await ep.goto(base+'/_preview');ok(await ep.locator('[data-project="'+projectId+'"]').getByText('Aprovada internamente',{exact:true}).isVisible(),'queue shows exact reviewed current revision');
 await ep.goto(historyUrl);
 ok(await ep.locator('[data-version]').count()===20&&await ep.locator('[data-version="60"]').isVisible(),'history has bounded newest window');
 ok(await ep.getByRole('button',{name:'Registrar revisão editorial'}).count()===0,'already-reviewed newest versions offer no duplicate decision');
 await ep.getByRole('link',{name:'Versões anteriores'}).click();
 ok(await ep.locator('[data-version="40"]').isVisible()&&await ep.locator('[data-version="21"]').isVisible(),'history moves to middle window');
 await ep.getByRole('link',{name:'Versões anteriores'}).click();
 ok(await ep.locator('[data-version="20"]').isVisible()&&await ep.locator('[data-version="1"]').isVisible(),'history reaches oldest versions beyond first window');
 ok(await ep.getByRole('button',{name:'Registrar revisão editorial'}).count()===0,'all prior decisions remain known beyond100 total review records');
 await ep.getByRole('link',{name:'Versões mais recentes'}).click();
 ok(await ep.locator('[data-version="40"]').isVisible()&&await ep.locator('[data-version="21"]').isVisible(),'history returns to adjacent newer window');
 ok((await a.request.get(historyUrl+'?before_revision=0')).status()===422,'invalid revision boundary rejected');
 await p.goto(historyUrl+'?before_revision=41');
 ok(await p.locator('input[name=expected_revision]').inputValue()==='60'&&await p.getByLabel('Chamada principal').inputValue()==='Histórico sintético versão 60','historical page edits only the actual current briefing and revision');
 await p.getByLabel('Chamada principal').fill('Histórico atual após consulta anterior');
 await p.getByRole('button',{name:'Gerar nova versão privada'}).click();await p.waitForURL(historyUrl);
 ok(await p.locator('[data-version="61"]').isVisible()&&await p.locator('input[name=expected_revision]').inputValue()==='61','edit from history appends61 from real current60');
 await ep.goto(historyUrl);
 ok(await ep.getByRole('button',{name:'Registrar revisão editorial'}).count()===1,'new current version requires its own review while old versions retain prior decisions');
 // The comparison is a real GET journey with page JavaScript disabled. Its
 // selectors identify historical values independently of the current edit form.
 const comparisonContext=await ctx('author',{javaScriptEnabled:false});
 const comparisonPage=await comparisonContext.newPage();
 ok((await comparisonPage.goto(one)).status()===200,'comparison starts from the actual private project');
 await comparisonPage.locator('input[name=compare_from]').fill('1');
 await comparisonPage.locator('input[name=compare_to]').fill('2');
 await comparisonPage.getByRole('button',{name:'Comparar versões',exact:true}).click();
 await comparisonPage.waitForURL(url=>url.searchParams.get('compare_from')==='1'&&url.searchParams.get('compare_to')==='2');
 const comparisonPanel=comparisonPage.getByTestId('editorial-comparison');
 const comparedValue=(side,field)=>comparisonPanel.locator('[data-compare-side="'+side+'"] [data-field="'+field+'"]');
 ok(await comparisonPanel.isVisible()&&await comparisonPanel.locator('[data-compare-side="from"][data-compare-revision="1"]').isVisible()&&
    await comparisonPanel.locator('[data-compare-side="to"][data-compare-revision="2"]').isVisible(),'ordinary GET form compares exact versions without JavaScript');
 for(const [field,before,after] of [
   ['headline',original.headline,revisedBrief.headline],
   ['description',original.description,revisedBrief.description],
   ['callToAction',original.call_to_action,revisedBrief.callToAction],
 ]) {
   ok((await comparedValue('from',field).innerText()).trim()===before&&
      (await comparedValue('to',field).innerText()).trim()===after&&
      await comparedValue('from',field).getAttribute('data-field-changed')==='true'&&
      await comparedValue('to',field).getAttribute('data-field-changed')==='true','comparison shows exact before/after and changed state for '+field);
 }
 ok((await comparedValue('from','brand').innerText()).trim()===original.brand&&
    (await comparedValue('to','brand').innerText()).trim()===original.brand&&
    await comparedValue('to','brand').getAttribute('data-field-changed')==='false','unchanged brand is not presented as an editorial difference');
 ok(JSON.stringify(await comparedValue('from','benefits').locator('li').allTextContents())===JSON.stringify(original.benefits.split('\n'))&&
    JSON.stringify(await comparedValue('to','benefits').locator('li').allTextContents())===JSON.stringify(revisedBrief.benefits),
    'comparison preserves ordered benefit text from both persisted versions');
 ok(await comparisonPanel.locator('[data-comparison-probe],script').count()===0&&
    (await comparedValue('to','benefits').innerText()).includes(revisedBrief.benefits[1]),'comparison escapes markup as visible text without inserting elements');
 const historicalComparisonUrl=historyUrl+'?before_revision=41&compare_from=1&compare_to=60';
 await comparisonPage.goto(historyUrl+'?before_revision=41');
 await comparisonPage.locator('input[name=compare_from]').fill('1');
 await comparisonPage.locator('input[name=compare_to]').fill('60');
 const historicalComparisonResponse=comparisonPage.waitForResponse(r=>r.url()===historicalComparisonUrl&&r.request().method()==='GET');
 await comparisonPage.getByRole('button',{name:'Comparar versões',exact:true}).click();
 ok((await historicalComparisonResponse).status()===200&&new URL(comparisonPage.url()).searchParams.get('before_revision')==='41',
    'comparison GET form preserves the historical page when requesting versions outside its window');
 ok(await comparisonPage.locator('[data-version="40"]').isVisible()&&await comparisonPage.locator('[data-version="21"]').isVisible()&&
    await comparisonPage.locator('[data-version="1"],[data-version="60"]').count()===0,'history stays on its middle page while comparison reads versions1 and60');
 ok(await comparisonPanel.locator('[data-compare-side="from"][data-compare-revision="1"]').isVisible()&&
    await comparisonPanel.locator('[data-compare-side="to"][data-compare-revision="60"]').isVisible()&&
    (await comparedValue('from','headline').innerText()).trim()==='Histórico sintético versão 1'&&
    (await comparedValue('to','headline').innerText()).trim()==='Histórico sintético versão 60'&&
    (await comparedValue('from','description').innerText()).trim()==='Conteúdo fictício da versão 1'&&
    (await comparedValue('to','description').innerText()).trim()==='Conteúdo fictício da versão 60','comparison resolves each immutable briefing independently of the paginated window');
 ok(await comparisonPage.locator('input[name=expected_revision]').inputValue()==='61'&&
    await comparisonPage.getByLabel('Chamada principal').inputValue()==='Histórico atual após consulta anterior','comparing old revisions keeps the edit form at the actual head61');
 await comparisonPage.setViewportSize({width:1440,height:1000});
 ok(await comparisonPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'comparison fits desktop viewport');
 await comparisonPanel.screenshot({path:dir+'/comparison-desktop.png'});
 await comparisonPage.setViewportSize({width:390,height:844});
 ok(await comparisonPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'comparison fits mobile viewport without horizontal overflow');
 await comparisonPanel.screenshot({path:dir+'/comparison-mobile.png'});
 for(const query of ['compare_from=1','compare_to=2','compare_from=2&compare_to=2','compare_from=2&compare_to=1',
   'compare_from=0&compare_to=2','compare_from[]=1&compare_to=2','compare_from=1&compare_to[]=2']) {
   ok((await a.request.get(one+'?'+query)).status()===422,'invalid comparison pair rejected: '+query);
 }
 ok((await a.request.get(one+'?compare_from=1&compare_to=9999')).status()===404,'valid comparison pair with absent revision returns404');
 const foreignComparison=await o.request.get(historicalComparisonUrl);
 const foreignComparisonBody=await foreignComparison.text();
 ok([403,404].includes(foreignComparison.status())&&!foreignComparisonBody.includes('Histórico sintético versão')&&
    !foreignComparisonBody.includes('data-compare-side'),'cross-company comparison discloses neither briefing nor comparison panel');
 const comparisonEditForm=comparisonPage.locator('form:has(input[name=expected_revision])');
 const editAction=new URL(await comparisonEditForm.getAttribute('action'),base);
 ok(!editAction.searchParams.has('compare_from')&&!editAction.searchParams.has('compare_to')&&
    await comparisonEditForm.locator('[name=compare_from],[name=compare_to]').count()===0,
    'comparison selectors do not become edit action or payload fields');
 await ep.goto(historyUrl+'?compare_from=1&compare_to=60');
 const comparisonReviewForm=ep.locator('form.review').first();
 const reviewAction=new URL(await comparisonReviewForm.getAttribute('action'),base);
 ok(reviewAction.pathname.endsWith('/versions/61/review')&&!reviewAction.searchParams.has('compare_from')&&
    !reviewAction.searchParams.has('compare_to')&&await comparisonReviewForm.locator('[name=compare_from],[name=compare_to]').count()===0,
    'review action stays on the actual unreviewed version and excludes comparison selectors');
 const comparisonRecovery=await require('./comparison-recovery.cjs')({ctx,a,e,o,base,dir,historyUrl,docker,ok});
 const comparisonEdit={
   _token:await comparisonEditForm.locator('input[name=_token]').getAttribute('value'),
   product:'onepage',brand:await comparisonPage.getByLabel('Marca fictícia').inputValue(),
   headline:'Histórico atualizado depois da comparação',
   description:await comparisonPage.getByLabel('Descrição',{exact:true}).inputValue(),
   call_to_action:await comparisonPage.getByLabel('Chamada do botão').inputValue(),
   benefits:await comparisonPage.getByLabel('Benefícios').inputValue(),expected_revision:'61',
 };
 const editWithComparisonQuery=await comparisonContext.request.post(historyUrl+'?before_revision=41&compare_from[]=1&compare_to=0',
   {form:comparisonEdit,maxRedirects:0});
 ok(editWithComparisonQuery.status()===302,'valid edit ignores malformed GET-only comparison selectors');
 const afterComparisonEdit=new URL(editWithComparisonQuery.headers().location,base);
 ok(!afterComparisonEdit.searchParams.has('compare_from')&&!afterComparisonEdit.searchParams.has('compare_to'),
    'successful edit redirects without carrying comparison selectors');
 await comparisonPage.goto(afterComparisonEdit.toString());
 ok(await comparisonPage.locator('input[name=expected_revision]').inputValue()==='62'&&
    await comparisonPage.getByLabel('Chamada principal').inputValue()===comparisonEdit.headline,
    'editing after historical comparison appends62 from actual head61');
 await comparisonContext.close();
 await require('./editorial-feedback.cjs')({a,e,o,p,ep,base,dir,historyUrl,ok});
 await require('./queue-search.cjs')({ctx,a,o,base,dir,historyUrl,ok});
 const queryLogProbe='SITES_SEARCH_QUERY_LOG_PROBE_20261118';
 ok((await a.request.get(base+'/_preview?q='+queryLogProbe)).status()===200,'synthetic query log probe completes a normal private search');
 ok([403,404].includes((await o.request.get(one)).status()),'cross company workspace denied');ok((await o.request.get(one+'/versions/1/html')).status()===403,'cross company bytes denied');
 await p.setViewportSize({width:1440,height:1000});await p.goto(one);await p.screenshot({path:dir+'/workspace-desktop.png',fullPage:true});
 await p.setViewportSize({width:390,height:844});await p.screenshot({path:dir+'/workspace-mobile.png',fullPage:true});ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile no horizontal overflow');
 await p.goto(base+'/_preview');await fill('landing_page','Uma oferta com foco em oportunidades');
 await p.getByLabel('Marca fictícia').fill('漢'.repeat(120));
 await p.getByLabel('Descrição',{exact:true}).fill('漢'.repeat(600));
 await p.getByLabel('Benefícios').fill(Array(8).fill('漢'.repeat(180)).join('\n'));
 await p.getByRole('button',{name:'Criar demonstração'}).click();await p.waitForURL('**/_preview/projects/*');const landing=p.url();ok(landing!==one,'second product persisted');
 ok(await p.getByLabel('Marca fictícia').inputValue()==='漢'.repeat(120),'Unicode briefing exceeding16k encoded persists within character limits');
 await p.goto(base+'/_preview?status=awaiting_review&format=landing_page');
 ok(await p.locator('[data-project]').count()===1&&await p.getByText('Uma oferta com foco em oportunidades',{exact:true}).isVisible(),'landing filter locates current pending project');
 await p.goto(landing+'/versions/1/html');await p.screenshot({path:dir+'/landing-mobile.png',fullPage:true});ok(await p.getByRole('heading',{name:'Uma oferta com foco em oportunidades'}).isVisible(),'real renderer visible');
 await require('./brief-validation.cjs')({ctx,o,base,dir,ok});
 await require('./inert-text.cjs')({ctx,base,dir,ok});
 docker(['restart','app']);
 let restored=false;for(let n=0;n<20;n++){try{const r=await a.request.get(one);if(r.status()===200){restored=true;break;}}catch{}await new Promise(r=>setTimeout(r,300));}ok(restored,'app restart retains DB and immutable versions');
 await p.setViewportSize({width:1440,height:1000});await p.goto(one+'/versions/1/html');await p.screenshot({path:dir+'/onepage-desktop.png',fullPage:true});
 docker(['exec','-T','db','psql','-U','postgres','-d','sites_preview_http_ci','-v','ON_ERROR_STOP=1','-c',"UPDATE sites_preview.principals SET revoked_at=now() WHERE database_login='sites_preview_author'"]);
 ok((await a.request.get(one+'/versions/1/html')).status()===403,'revocation blocks repeated HTML request');
 const revokedSearch=await a.request.get(base+'/_preview?q='+encodeURIComponent('Navegação CI'));
 ok(revokedSearch.status()===403&&!(await revokedSearch.text()).includes('Navegação CI 01'),'revocation blocks a previously valid queue search');
 const revokedComparison=await a.request.get(one+'?compare_from=1&compare_to=2');
 ok(revokedComparison.status()===403&&!(await revokedComparison.text()).includes(revisedBrief.headline),
    'revocation blocks a comparison that previously succeeded without exposing historical copy');
 await comparisonRecovery.verifyRevocation();
 const privateLogs=docker(['logs','--no-color','app','proxy']);
 ok(!privateLogs.includes(queryLogProbe),'the query-only synthetic search term is absent from app and proxy logs');
 const audit=docker(['exec','-T','db','psql','-U','postgres','-d','sites_preview_http_ci','-Atc','SELECT count(*) FROM sites_preview.audit']);ok(Number(audit.trim())>=6,'persistent DB audit');
 await b.close();fs.writeFileSync(dir+'/result.json',JSON.stringify({schema:'SITES_DEMO_HTTP_CI_V1',checks,source:'synthetic_separate_laravel_nginx_pg18_tls',operational:false,installed_on_vps:false}));console.log('PREVIEW HTTP BROWSER PASS: '+checks+' checks; persisted Laravel/FPM/PG18 verify-full, nominal proxy auth, CSRF, two products, versions, escaped editorial comparison without JavaScript, separate editorial principal, revocation, restart, desktop/mobile.');
})().catch(e=>{console.error('PREVIEW HTTP BROWSER FAILED:',e.message);process.exit(1)});
