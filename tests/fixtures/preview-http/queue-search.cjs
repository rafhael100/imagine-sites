module.exports=async({ctx,a,o,base,dir,historyUrl,ok})=>{
 const context=await ctx('author',{javaScriptEnabled:false});
 context.setDefaultTimeout(20000);
 context.setDefaultNavigationTimeout(20000);
 const page=await context.newPage();
 const queue=base+'/_preview';
 const rows=()=>page.locator('[data-project]');
 const ids=()=>rows().evaluateAll(nodes=>nodes.map(node=>node.getAttribute('data-project')));
 const queryUrl=(q,extra={})=>queue+'?'+new URLSearchParams({...extra,q}).toString();
 const submit=async()=>{
   const expected={
     q:await page.getByLabel('Buscar projeto',{exact:true}).inputValue(),
     status:await page.getByLabel('Parecer na fila').inputValue(),
     format:await page.getByLabel('Formato da fila').inputValue(),
   };
   const matches=url=>url.pathname==='/_preview'&&
     Object.entries(expected).every(([key,value])=>url.searchParams.get(key)===value);
   const response=page.waitForResponse(r=>r.request().isNavigationRequest()&&
     r.request().method()==='GET'&&matches(new URL(r.url())));
   try {
     await Promise.all([
       response,
       page.waitForURL(matches,{waitUntil:'load',timeout:20000}),
       page.getByRole('button',{name:'Aplicar filtros',exact:true}).click(),
     ]);
   } catch(error) {
     const actual=new URL(page.url());
     const rendered=await page.getByLabel('Buscar projeto',{exact:true}).inputValue({timeout:1000}).catch(()=>null);
     console.error('SEARCH_SUBMIT_DIAGNOSTIC '+JSON.stringify({
       expectedCodepoints:Array.from(expected.q).length,
       queryCodepoints:Array.from(actual.searchParams.get('q')||'').length,
       renderedCodepoints:rendered===null?null:Array.from(rendered).length,
       queryMatches:actual.searchParams.get('q')===expected.q,
       renderedMatches:rendered===expected.q,
       statusMatches:actual.searchParams.get('status')===expected.status,
       formatMatches:actual.searchParams.get('format')===expected.format,
     }));
     throw error;
   }
   return response;
 };
 await page.goto(queue);
 await page.getByLabel('Buscar projeto',{exact:true}).fill('');
 ok((await submit()).status()===200&&await rows().count()===50,
    'empty search survives Laravel empty-string middleware and keeps the normal queue');
 await page.getByLabel('Buscar projeto',{exact:true}).fill('\u00a0NAVEGAÇÃO CI\u3000');
 await page.getByLabel('Parecer na fila').selectOption('awaiting_review');
 await page.getByLabel('Formato da fila').selectOption('onepage');
 ok((await submit()).status()===200&&await rows().count()===50&&
    await page.getByLabel('Buscar projeto',{exact:true}).inputValue()==='NAVEGAÇÃO CI',
    'Unicode search trims boundary spaces and applies with status and product filters');
 const first=await ids();
 ok(!first.includes('60000000-0000-4000-8000-000000000001'),
    'search applies before the first bounded queue page');
 await page.getByRole('link',{name:'Próximos projetos'}).click();
 const second=await ids();
 const secondUrl=new URL(page.url());
 ok(second.length===5&&new Set([...first,...second]).size===55&&
    secondUrl.searchParams.get('q')==='NAVEGAÇÃO CI'&&
    secondUrl.searchParams.get('status')==='awaiting_review'&&
    secondUrl.searchParams.get('format')==='onepage'&&secondUrl.searchParams.has('cursor'),
    'literal search keeps its filters across all55 projects without page overlap');
 await page.getByRole('link',{name:'Navegação CI 01',exact:true}).click();
 ok(new URL(page.url()).searchParams.get('q')==='NAVEGAÇÃO CI'&&
    new URL(page.url()).searchParams.get('cursor')===secondUrl.searchParams.get('cursor'),
    'opening a result retains search and its page cursor');
 await page.getByRole('link',{name:'Voltar à fila'}).click();
 ok(JSON.stringify(await ids())===JSON.stringify(second),'return to queue restores the same searched page');
 await page.getByLabel('Buscar projeto',{exact:true}).fill('Navegação CI 01');
 await submit();
 ok(await rows().count()===1&&!new URL(page.url()).searchParams.has('cursor'),
    'changing the search starts at the first result page');
 await page.getByRole('link',{name:'Limpar busca',exact:true}).click();
 const cleared=new URL(page.url());
 ok(!cleared.searchParams.has('q')&&!cleared.searchParams.has('cursor')&&
    cleared.searchParams.get('status')==='awaiting_review'&&cleared.searchParams.get('format')==='onepage',
    'clearing only search retains status and product while resetting pagination');
 const foreign=await o.request.get(secondUrl.toString());
 ok(foreign.status()===200&&!(await foreign.text()).includes('Navegação CI 01'),
    'a valid search and cursor do not disclose another company');

 const markup='<img data-search-probe="literal" src=x>';
 await page.goto(queryUrl(markup));
 ok(await rows().count()===0&&await page.getByTestId('queue-empty').isVisible()&&
    await page.getByLabel('Buscar projeto',{exact:true}).inputValue()===markup&&
    await page.locator('[data-search-probe]').count()===0,
    'zero-result search is visible and markup remains escaped in the field and message');
 const boundary='😀'.repeat(100);
 await page.getByLabel('Buscar projeto',{exact:true}).fill(boundary);
 const filledBoundary=await page.getByLabel('Buscar projeto',{exact:true}).inputValue();
 ok(filledBoundary===boundary,'100 astral codepoints fill the actual search input; codepoints='+
    Array.from(filledBoundary).length);
 const boundaryResponse=await submit();
 const boundaryValue=await page.getByLabel('Buscar projeto',{exact:true}).inputValue();
 ok(boundaryResponse.status()===200&&boundaryValue===boundary,
    '100 astral Unicode codepoints pass the real no-JavaScript GET form; status='+
    boundaryResponse.status()+', rendered_codepoints='+Array.from(boundaryValue).length);
 for(const encoded of ['%00','%0A','%7F','%C2%85','%E2%80%A8','%C3%28',encodeURIComponent('漢'.repeat(101))]){
   ok((await a.request.get(queue+'?q='+encoded)).status()===422,
      'invalid or oversized search rejected before database query: '+encoded.slice(0,15));
 }
 ok((await a.request.get(queue+'?q[]=x')).status()===422,'array-valued search is rejected');

 // The search is navigation only. Strict POST input validation proves it is
 // carried in the URL, without being added to the mutable briefing body.
 const brand='AÇÃO _50% \\ Café Straße';
 const term='ação _50% \\ café';
 await page.goto(queryUrl(term,{status:'awaiting_review',format:'onepage'}));
 await page.getByLabel('Formato',{exact:true}).selectOption('onepage');
 await page.getByLabel('Marca fictícia').fill(brand);
 await page.getByLabel('Chamada principal').fill('Oferta ÓRBITA editorial');
 await page.getByLabel('Descrição',{exact:true}).fill('Conteúdo inteiramente fictício para localizar projetos.');
 await page.getByLabel('Chamada do botão').fill('Conheça a proposta');
 await page.getByLabel('Benefícios').fill('Informações claras');
 await page.getByRole('button',{name:'Criar demonstração',exact:true}).click();
 await page.waitForURL(url=>url.pathname.startsWith('/_preview/projects/'));
 const project=new URL(page.url());
 const projectId=project.pathname.split('/').pop();
 ok(project.searchParams.get('q')===term&&project.searchParams.get('format')==='onepage'&&
    project.searchParams.get('status')==='awaiting_review'&&
    await page.getByLabel('Marca fictícia').inputValue()===brand,
    'creation carries the search context without changing the submitted briefing');
 for(const q of ['%','_','\\','ação _50%','CAFÉ','STRASSE','órbita']){
   await page.goto(queryUrl(q));
   ok(JSON.stringify(await ids())===JSON.stringify([projectId]),
      'actual queue finds the exact current brand or headline for literal query '+q);
 }
 const noAccent=await page.goto(queryUrl('cafe'));
 ok(noAccent.status()===200&&await page.getByTestId('queue-empty').isVisible()&&await rows().count()===0,'case-insensitive search does not silently remove accents');
 await page.goto(queryUrl(term,{status:'awaiting_review',format:'onepage'}));
 await page.setViewportSize({width:1440,height:1000});
 await page.getByTestId('preview-queue').screenshot({path:dir+'/queue-search-desktop.png'});
 await page.setViewportSize({width:390,height:844});
 ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
    'search field and literal result fit mobile viewport');
 await page.getByTestId('preview-queue').screenshot({path:dir+'/queue-search-mobile.png'});

 await page.goto(base+project.pathname+'?'+new URLSearchParams({status:'awaiting_review',format:'onepage',q:'órbita'}));
 await page.getByLabel('Chamada principal').fill('Título atualizado privado');
 await page.getByRole('button',{name:'Gerar nova versão privada',exact:true}).click();
 await page.waitForURL(url=>url.pathname===project.pathname&&url.searchParams.get('q')==='órbita');
 ok(await page.locator('input[name=expected_revision]').inputValue()==='2',
    'editing from a searched result appends the current version and preserves navigation');
 await page.getByRole('link',{name:'Voltar à fila'}).click();
 ok(await page.getByTestId('queue-empty').isVisible()&&await rows().count()===0&&new URL(page.url()).searchParams.get('q')==='órbita',
    'an old matching headline does not make the current version match');

 const historyContext=new URLSearchParams({status:'changes_requested',format:'onepage',q:'histórico',before_revision:'41'});
 await page.goto(historyUrl+'?'+historyContext);
 await page.locator('input[name=compare_from]').fill('1');
 await page.locator('input[name=compare_to]').fill('60');
 await page.getByRole('button',{name:'Comparar versões',exact:true}).click();
 await page.waitForURL(url=>url.searchParams.get('compare_to')==='60');
 ok(new URL(page.url()).searchParams.get('q')==='histórico'&&
    new URL(page.url()).searchParams.get('before_revision')==='41'&&
    await page.getByTestId('editorial-comparison').isVisible(),
    'comparison GET form preserves search and the historical page without JavaScript');
 await page.getByRole('link',{name:'Versões anteriores'}).click();
 ok(new URL(page.url()).searchParams.get('q')==='histórico'&&
    new URL(page.url()).searchParams.get('compare_to')==='60'&&
    await page.locator('[data-version="1"]').isVisible(),
    'history paging retains the search and explicit comparison');
 await context.close();
};
