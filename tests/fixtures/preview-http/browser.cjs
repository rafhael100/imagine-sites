const {chromium}=require('playwright');const fs=require('fs'),cp=require('child_process'),crypto=require('crypto'),path=require('path');
(async()=>{
 const vault=JSON.parse(fs.readFileSync(process.env.PREVIEW_CI_MATERIAL+'/browser.json','utf8'));
 const dir=process.env.PREVIEW_HTTP_EVIDENCE;fs.mkdirSync(dir,{recursive:true});let checks=0;
 const ok=(v,m)=>{if(!v)throw Error(m);checks++;};
 const compose=path.join(process.env.GITHUB_WORKSPACE,'tests/fixtures/preview-http/compose.yaml');
 const docker=(args)=>cp.execFileSync('docker',['compose','-f',compose,...args],{stdio:['ignore','pipe','pipe'],env:process.env}).toString();
 const b=await chromium.launch({headless:true});
 // Only disposable one-run certificates in this CI browser. curl --cacert independently verifies HTTPS before this script.
 const ctx=async(name)=>b.newContext({ignoreHTTPSErrors:true,httpCredentials:name?{username:name,password:vault[name]}:undefined});
 const a=await ctx('author'),e=await ctx('editor'),o=await ctx('other'),anon=await ctx();
 const base='https://127.0.0.1:18496';
 ok((await anon.request.get(base+'/_preview')).status()===401,'anonymous denied');
 ok((await anon.request.get(base+'/_preview',{headers:{'Remote-User':'author','X-Imagine-Preview-Authenticated':'nginx-basic-v1'}})).status()===401,'header impersonation denied');
 const p=await a.newPage();await p.goto(base+'/_preview');ok(await p.getByText('DEMONSTRAÇÃO PRIVADA',{exact:true}).isVisible(),'demo label');
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
 let html=(await a.request.get(one+'/versions/1/html'));let body=await html.body();let digest=crypto.createHash('sha256').update(body).digest('hex');ok(html.status()===200&&await p.getByText('SHA-256: '+digest,{exact:true}).isVisible(),'stored exact digest');
 ok(html.headers()['cache-control'].includes('no-store')&&html.headers()['content-security-policy'].includes('sandbox'),'private sandboxed HTML');
 ok((await a.request.post(one)).status()===419,'CSRF missing denied');
 const bad=await a.request.post(one,{form:{_token:await p.locator('input[name=_token]').first().getAttribute('value'),company_id:'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'}});ok(bad.status()===422,'browser company field rejected');
 await p.getByLabel('Chamada principal').fill('Segunda versão demonstrativa');await p.getByRole('button',{name:'Gerar nova versão privada'}).click();await p.waitForURL(one);
 ok(await p.getByText('Versão 2',{exact:true}).isVisible()&&await p.getByText('Versão 1',{exact:true}).isVisible(),'version history persisted');
 const conflict=await a.request.post(one,{form:{...original,headline:'Briefing de outra aba preservado'}});
 const conflictBody=await conflict.text();
 ok(conflict.status()===409&&conflictBody.includes('Briefing de outra aba preservado')&&conflictBody.includes('Outra versão foi criada'),'concurrent edit returns409 with briefing retained');
 ok((await a.request.get(one+'/versions/3/html')).status()===403,'validation and conflicts created no extra revision');
 ok(crypto.createHash('sha256').update(await (await a.request.get(one+'/versions/1/html')).body()).digest('hex')===digest,'old immutable version');
 const own=await a.request.post(one+'/versions/2/review',{form:{_token:await p.locator('input[name=_token]').first().getAttribute('value'),sha256:(await p.locator('code').first().textContent()).replace('SHA-256: ',''),decision:'approved_internal'},headers:{'Remote-User':'editor'}});ok(own.status()===403,'own editorial review denied and identity header ignored');
 const ep=await e.newPage();await ep.goto(base+'/_preview');
 const projectId=new URL(one).pathname.split('/').pop();
 ok(await ep.locator('[data-project="'+projectId+'"]').getByText('Aguardando revisão',{exact:true}).isVisible(),'queue shows current revision awaiting editorial review');
 ok(await ep.getByRole('button',{name:'Criar demonstração'}).count()===0,'reviewer sees queue without author-only form');
 await ep.goto(one);ok(await ep.getByRole('button',{name:'Gerar nova versão privada'}).count()===0,'reviewer sees briefing read-only');await ep.getByLabel('Parecer editorial desta versão').first().selectOption('approved_internal');await ep.getByRole('button',{name:'Registrar revisão editorial'}).first().click();await ep.waitForURL(one);ok(await ep.getByText(/Versão 2: Aprovada internamente/).isVisible(),'different named editorial reviewer');
 await ep.goto(base+'/_preview');ok(await ep.locator('[data-project="'+projectId+'"]').getByText('Aprovada internamente',{exact:true}).isVisible(),'queue shows exact reviewed current revision');
 ok([403,404].includes((await o.request.get(one)).status()),'cross company workspace denied');ok((await o.request.get(one+'/versions/1/html')).status()===403,'cross company bytes denied');
 await p.setViewportSize({width:1440,height:1000});await p.goto(one);await p.screenshot({path:dir+'/workspace-desktop.png',fullPage:true});
 await p.setViewportSize({width:390,height:844});await p.screenshot({path:dir+'/workspace-mobile.png',fullPage:true});ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile no horizontal overflow');
 await p.goto(base+'/_preview');await fill('landing_page','Uma oferta com foco em oportunidades');
 await p.getByLabel('Marca fictícia').fill('漢'.repeat(120));
 await p.getByLabel('Descrição',{exact:true}).fill('漢'.repeat(600));
 await p.getByLabel('Benefícios').fill(Array(8).fill('漢'.repeat(180)).join('\n'));
 await p.getByRole('button',{name:'Criar demonstração'}).click();await p.waitForURL('**/_preview/projects/*');const landing=p.url();ok(landing!==one,'second product persisted');
 ok(await p.getByLabel('Marca fictícia').inputValue()==='漢'.repeat(120),'Unicode briefing exceeding16k encoded persists within character limits');
 await p.goto(landing+'/versions/1/html');await p.screenshot({path:dir+'/landing-mobile.png',fullPage:true});ok(await p.getByRole('heading',{name:'Uma oferta com foco em oportunidades'}).isVisible(),'real renderer visible');
 docker(['restart','app']);
 let restored=false;for(let n=0;n<20;n++){try{const r=await a.request.get(one);if(r.status()===200){restored=true;break;}}catch{}await new Promise(r=>setTimeout(r,300));}ok(restored,'app restart retains DB and immutable versions');
 await p.setViewportSize({width:1440,height:1000});await p.goto(one+'/versions/1/html');await p.screenshot({path:dir+'/onepage-desktop.png',fullPage:true});
 docker(['exec','-T','db','psql','-U','postgres','-d','sites_preview_http_ci','-v','ON_ERROR_STOP=1','-c',"UPDATE sites_preview.principals SET revoked_at=now() WHERE database_login='sites_preview_author'"]);
 ok((await a.request.get(one+'/versions/1/html')).status()===403,'revocation blocks repeated HTML request');
 const audit=docker(['exec','-T','db','psql','-U','postgres','-d','sites_preview_http_ci','-Atc','SELECT count(*) FROM sites_preview.audit']);ok(Number(audit.trim())>=6,'persistent DB audit');
 await b.close();fs.writeFileSync(dir+'/result.json',JSON.stringify({schema:'SITES_DEMO_HTTP_CI_V1',checks,source:'synthetic_separate_laravel_nginx_pg18_tls',operational:false,installed_on_vps:false}));console.log('PREVIEW HTTP BROWSER PASS: '+checks+' checks; persisted Laravel/FPM/PG18 verify-full, nominal proxy auth, CSRF, two products, versions, separate editorial principal, revocation, restart, desktop/mobile.');
})().catch(e=>{console.error('PREVIEW HTTP BROWSER FAILED:',e.message);process.exit(1)});
