const crypto=require('crypto');

module.exports=async({ctx,base,dir,ok})=>{
 const context=await ctx('author',{javaScriptEnabled:false});
 const previewContext=await ctx('author');
 const origin=new URL(base).origin;
 let externalAttempts=0,dialogs=0;
 for(const current of [context,previewContext]){
   current.setDefaultTimeout(20000);
   current.setDefaultNavigationTimeout(20000);
   // A regression must fail without sending the fixture's literal URLs out.
   await current.route('**/*',async route=>{
     if(new URL(route.request().url()).origin!==origin){
       externalAttempts++;
       return route.abort();
     }
     return route.continue();
   });
 }
 const page=await context.newPage();
 const preview=await previewContext.newPage();
 preview.on('dialog',async dialog=>{dialogs++;await dialog.dismiss();});
 const fields=['brand','headline','description','call_to_action','benefits'];
 const copy=(product,version)=>{
   const url='https://example.invalid/v'+version;
   return {
     brand:'Estúdio '+product+' v'+version+' '+url+' onboarding=texto',
     headline:'Referência v'+version+' http://example.invalid/v'+version+' onclick=texto e javascript:exemplo',
     description:'Consulte '+url+'?etapa=1&modo=texto; onload=literal. Trecho literal '+
       '<a data-inert-text-probe="literal" href="'+url+'">referência</a> e '+
       '<script>window.__inertTextExecuted=1</script>.',
     call_to_action:'Ver '+url+' onboarding=texto',
     benefits:[url+'?etapa=1&modo=texto onclick=literal',
       'Texto v'+version+': &lt;script&gt; e <img data-inert-text-probe="literal" src="'+url+'">'].join('\n'),
   };
 };
 const fill=async values=>{for(const name of fields)await page.locator('#'+name).fill(values[name]);};
 const submit=async(button,revision,label)=>{
   const target=new URL(await page.locator('form:has(input[name=brand])').getAttribute('action'),base).toString();
   // Register navigation before clicking: a revision redirects to the same URL.
   const [response,loaded]=await Promise.all([
     page.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='POST'&&r.url()===target),
     page.waitForNavigation({waitUntil:'load'}),
     page.getByRole('button',{name:button,exact:true}).click(),
   ]);
   ok(response.status()===302&&loaded!==null&&loaded.status()===200,
      'inert text '+label+' submits through the real form and redirect; POST='+response.status()+
      ', GET='+(loaded===null?'none':loaded.status()));
   ok(new URL(page.url()).pathname.startsWith('/_preview/projects/')&&
      await page.locator('input[name=expected_revision]').inputValue()===String(revision),
      'inert text '+label+' persists exactly revision '+revision);
 };
 const stored=async(project,revision,label)=>{
   const response=await context.request.get(project+'/versions/'+revision+'/html');
   ok(response.status()===200,'inert text '+label+' stored HTML is readable; status='+response.status());
   const bytes=await response.body();
   const digest=crypto.createHash('sha256').update(bytes).digest('hex');
   ok(await page.locator('[data-version="'+revision+'"] code').textContent()==='SHA-256: '+digest,
      'inert text '+label+' stored bytes have the exact history digest');
   return {bytes,digest};
 };
 try {
   for(const product of ['onepage','landing_page']){
     const before=copy(product,1),after=copy(product,2);
     ok((await page.goto(base+'/_preview')).status()===200,
        'inert text '+product+' starts from the authenticated workspace');
     await page.locator('#product').selectOption(product);
     await fill(before);
     await submit('Criar demonstração →',1,product+' creation');
     const project=page.url();
     for(const field of fields)ok(await page.locator('#'+field).inputValue()===before[field],
       'inert text '+product+' initial '+field+' keeps literal URLs and expressions');
     const first=await stored(project,1,product+' revision 1');

     await fill(after);
     await submit('Gerar nova versão privada →',2,product+' revision');
     for(const field of fields)ok(await page.locator('#'+field).inputValue()===after[field],
       'inert text '+product+' revised '+field+' keeps literal URLs and expressions');
     const second=await stored(project,2,product+' revision 2');
     ok(first.digest!==second.digest,'inert text '+product+' revised text produces different immutable HTML');

     await page.locator('input[name=compare_from]').fill('1');
     await page.locator('input[name=compare_to]').fill('2');
     const matches=url=>url.pathname===new URL(project).pathname&&
       url.searchParams.get('compare_from')==='1'&&url.searchParams.get('compare_to')==='2';
     const [comparison]=await Promise.all([
       page.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='GET'&&matches(new URL(r.url()))),
       page.waitForURL(matches,{waitUntil:'load'}),
       page.getByRole('button',{name:'Comparar versões',exact:true}).click(),
     ]);
     ok(comparison.status()===200,'inert text '+product+' comparison returns200 instead of denying escaped text; status='+comparison.status());
     const panel=page.getByTestId('editorial-comparison');
     for(const [side,values,revision,saved] of [['from',before,1,first],['to',after,2,second]]){
       const column=panel.locator('[data-compare-side="'+side+'"][data-compare-revision="'+revision+'"]');
       ok(await column.isVisible()&&await column.locator('code').textContent()==='SHA-256: '+saved.digest,
          'inert text '+product+' comparison '+side+' identifies the exact immutable revision');
       for(const field of fields){
         const key=field==='call_to_action'?'callToAction':field;
         const value=column.locator('[data-field="'+key+'"]');
         const exact=field==='benefits'
           ?JSON.stringify(await value.locator('li').allTextContents())===JSON.stringify(values[field].split('\n'))
           :await value.textContent()===values[field];
         ok(exact&&await value.getAttribute('data-field-changed')==='true',
            'inert text '+product+' comparison preserves exact '+side+' '+field+' and marks its change');
       }
       ok(await column.locator('[data-field="productCode"]').getAttribute('data-field-changed')==='false',
          'inert text '+product+' comparison does not mark unchanged format');
     }
     ok(await panel.locator('[data-field-changed=true]').count()===10&&
        await panel.locator('[data-inert-text-probe],script,img').count()===0&&
        await page.locator('input[name=expected_revision]').inputValue()==='2',
        'inert text '+product+' comparison escapes markup, changes only five fields and preserves the current head');
     if(product==='onepage'){
       await page.setViewportSize({width:1440,height:1000});
       await panel.screenshot({path:dir+'/inert-text-comparison-desktop.png'});
       await page.setViewportSize({width:390,height:844});
       ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
          'inert text comparison wraps literal URLs and expressions on mobile');
       await panel.screenshot({path:dir+'/inert-text-comparison-mobile.png'});
       await page.setViewportSize({width:1280,height:900});
     }

     // Inspect the actual preview endpoint as a page, its supported surface.
     // No srcdoc, fixture iframe or transformed HTML substitutes for the bytes.
     for(const [revision,values,saved] of [[1,before,first],[2,after,second]]){
       const loaded=await preview.goto(project+'/versions/'+revision+'/html');
       ok(loaded.status()===200&&(await loaded.body()).equals(saved.bytes),
          'inert text '+product+' preview '+revision+' keeps exact stored bytes after comparison');
       const headers=await loaded.allHeaders(),csp=headers['content-security-policy']||'';
       // Both Laravel and the private Nginx add these fixed headers. Browser
       // stacks may expose repeated fields separately or as a combined value.
       // Every reported value must match; a conflicting value still fails.
       const [frameValues,referrerValues]=await Promise.all([
         loaded.headerValues('x-frame-options'),loaded.headerValues('referrer-policy'),
       ]);
       const only=(values,expected)=>{
         const tokens=values.flatMap(value=>value.split(/[,\n]/)).map(value=>value.trim());
         return tokens.length>0&&tokens.every(value=>value===expected);
       };
       ok(csp.includes('sandbox')&&csp.includes("default-src 'none'")&&csp.includes("frame-ancestors 'none'")&&
          only(frameValues,'DENY')&&(headers['cache-control']||'').includes('no-store')&&
          only(referrerValues,'no-referrer'),
          'inert text '+product+' preview '+revision+' retains real isolation headers; '+
          JSON.stringify({frame:frameValues,referrer:referrerValues}));
       ok(await preview.locator('.brand').textContent()===values.brand&&
          await preview.locator('#hero-title').textContent()===values.headline&&
          await preview.locator('.lead').textContent()===values.description&&
          await preview.locator('a.btn').textContent()===values.call_to_action&&
          JSON.stringify(await preview.locator('.benefits li').allTextContents())===JSON.stringify(values.benefits.split('\n')),
          'inert text '+product+' preview '+revision+' renders all five fields as exact visible text');
       ok(await preview.title()===values.headline+' | '+values.brand&&
          await preview.locator('meta[name=description]').getAttribute('content')===values.description&&
          await preview.locator('meta').count()===5,
          'inert text '+product+' preview '+revision+' preserves literal title and description metadata');
       const links=await preview.locator('a').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('href')));
       ok(JSON.stringify(links)===JSON.stringify(product==='onepage'?['#beneficios','#sobre','#contato']:['#contato']),
          'inert text '+product+' preview '+revision+' contains only the renderer internal anchors');
       const attributes=await preview.locator('*').evaluateAll(nodes=>{
         const blocked=[];
         let banners=0;
         for(const node of nodes){
           // PrivatePreviewWorkspace adds this exact inert banner to stored HTML.
           const banner=node.tagName==='ASIDE'&&node.attributes.length===2&&node.getAttribute('role')==='note'&&
             node.getAttribute('style')==='background:#F9941D;color:#222;padding:16px;text-align:center'&&
             node.textContent==='DEMONSTRAÇÃO PRIVADA — sem contratação, pagamento ou aceite.';
           if(banner)banners++;
           for(const attribute of node.attributes){
             if(attribute.name==='style'&&banner)continue;
             if(/^on/i.test(attribute.name)||['src','srcset','style','action','formaction','target','ping','download','xlink:href'].includes(attribute.name)){
               blocked.push(attribute.name);
             }
           }
         }
         return {blocked,banners};
       });
       ok(attributes.blocked.length===0&&attributes.banners===1&&
          await preview.locator('script,iframe,frame,object,embed,form,input,button,textarea,select,img,svg,math,link,base,[data-inert-text-probe]').count()===0&&
          await preview.evaluate(()=>window.__inertTextExecuted===undefined),
          'inert text '+product+' preview '+revision+' creates no active element, handler or script execution');
     }
   }
   ok(externalAttempts===0&&dialogs===0,
      'inert text browser journey attempts no external requests and opens no script dialogs');
 } finally {
   await context.close();
   await previewContext.close();
 }
};
