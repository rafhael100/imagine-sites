// Recovery is a read journey. The only POST deliberately carries an invalid
// navigation query and must be refused before its otherwise valid edit can run.
// SQL snapshots use only the parent's verified disposable HTTP database.
module.exports=async({ctx,a,base,dir,historyUrl,docker,ok})=>{
 const baselineChecks=312;
 let additionalChecks=0;
 const check=(value,label)=>{ok(value,'queue search recovery: '+label);additionalChecks++;};
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
 const queue=base+'/_preview';
 const filters={status:'awaiting_review',format:'onepage'};
 const queryUrl=values=>queue+'?'+new URLSearchParams(values);
 const ascii=' '+'A'.repeat(99)+' ';
 const before=snapshot();
 const context=await ctx('author',{javaScriptEnabled:false});
 context.setDefaultTimeout(20000);
 context.setDefaultNavigationTimeout(20000);
 const page=await context.newPage();
 const form=()=>page.getByTestId('queue-filters');
 const field=()=>form().getByLabel('Buscar projeto',{exact:true});
 const error=()=>page.getByTestId('queue-search-error');
 const rows=()=>page.locator('[data-project]');
 const ids=()=>rows().evaluateAll(nodes=>nodes.map(node=>node.getAttribute('data-project')));
 const submit=async(term)=>{
   await field().fill(term);
   const expected={q:term,
     status:await form().getByLabel('Parecer na fila').inputValue(),
     format:await form().getByLabel('Formato da fila').inputValue()};
   const [response]=await Promise.all([
     page.waitForResponse(response=>response.request().isNavigationRequest()&&
       response.request().method()==='GET'&&new URL(response.url()).pathname==='/_preview'&&
       hasQuery(response.url(),expected)),
     page.waitForNavigation({waitUntil:'load'}),
     form().getByRole('button',{name:'Aplicar filtros',exact:true}).click(),
   ]);
   return response;
 };
 const recovered=async(response,term,label)=>{
   check(response.status()===422&&await form().isVisible()&&await field().inputValue()===term&&
     await field().getAttribute('maxlength')===null&&await field().getAttribute('aria-invalid')==='true'&&
     await field().getAttribute('aria-describedby')==='queue-search-help queue-search-error'&&
     await error().isVisible()&&await error().getAttribute('id')==='queue-search-error'&&
     await error().getAttribute('role')==='alert'&&
     await error().textContent()==='Use até 100 caracteres na busca. Encurte o texto e tente novamente.'&&
     await rows().count()===0&&await page.getByTestId('queue-empty').count()===0&&
     await page.getByRole('navigation',{name:'Páginas da fila editorial',exact:true}).count()===0,
     label+' returns422 with the full input and associated error, withholding results, empty state and pagination');
 };
 const hardDenied=async(response,label)=>{
   const text=await response.text();
   check(response.status()===422&&!text.includes('data-testid="queue-filters"')&&
     !text.includes('data-testid="queue-search-error"')&&!text.includes('data-project=')&&
     !text.includes('name="expected_revision"'),label+' remains a hard422 without a recovered workspace');
 };
 let recoveryUrl;
 try {
   const searched={...filters,q:'NAVEGAÇÃO CI'};
   const firstResponse=await page.goto(queryUrl(searched));
   const first=await ids();
   const next=new URL(await page.getByRole('link',{name:'Próximos projetos'}).getAttribute('href'),base);
   const cursor=next.searchParams.get('cursor');
   check(firstResponse.status()===200&&first.length===50&&cursor&&
     hasQuery(next.toString(),{...searched,cursor}),
     'the filtered search supplies a genuine next cursor from its first50 matching projects');
   await page.getByRole('link',{name:'Próximos projetos'}).click();
   await page.waitForURL(next.toString());
   const second=await ids();
   check(second.length===5&&new Set([...first,...second]).size===55&&hasQuery(page.url(),{...searched,cursor}),
     'the real next link reaches the remaining five matching projects before recovery');

   await recovered(await submit(ascii),ascii,'101 ASCII codepoints including boundary spaces');
   check(hasQuery(page.url(),{...filters,q:ascii})&&
     await form().getByLabel('Parecer na fila').inputValue()===filters.status&&
     await form().getByLabel('Formato da fila').inputValue()===filters.format,
     'the actual GET form retains both filters, resets the old cursor and preserves raw edge spaces');

   // A bookmarked error may still contain a valid cursor. It is safe navigation,
   // but the invalid term belongs only to the editable search field.
   recoveryUrl=queryUrl({...filters,cursor,q:ascii});
   await recovered(await page.goto(recoveryUrl),ascii,'recoverable search with a genuine cursor');
   const back=await page.getByRole('link',{name:'Voltar à fila'}).getAttribute('href');
   const clear=await page.getByRole('link',{name:'Limpar busca',exact:true}).getAttribute('href');
   const create=page.locator('form[method="post"]');
   const links=await page.locator('a[href]').evaluateAll(nodes=>nodes.map(node=>node.getAttribute('href')));
   check(hasQuery(page.url(),{...filters,cursor,q:ascii})&&hasQuery(back,{...filters,cursor})&&
     hasQuery(await create.getAttribute('action'),{...filters,cursor})&&hasQuery(clear,filters)&&
     links.every(href=>!new URL(href,base).searchParams.has('q'))&&
     await create.locator('[name="q"]').count()===0&&await form().locator('[name="cursor"]').count()===0,
     'links and the POST action keep only safe navigation; clear and the correction form start at page1');
   const corrected=await submit(searched.q);
   check(corrected.status()===200&&hasQuery(page.url(),searched)&&
     JSON.stringify(await ids())===JSON.stringify(first)&&await error().count()===0&&
     await field().getAttribute('aria-invalid')==='false',
     'correcting the bookmarked error through the real form restores the exact first searched page');

   const han='漢'.repeat(101);
   await recovered(await submit(han),han,'101 Han codepoints');
   const single=await submit('Navegação CI 01');
   check(single.status()===200&&hasQuery(page.url(),{...filters,q:'Navegação CI 01'})&&
     await rows().count()===1&&await page.getByRole('link',{name:'Navegação CI 01',exact:true}).isVisible()&&
     await error().count()===0,
     'shortening the Han error in the no-JavaScript form produces the actual matching project');
   const emoji='😀'.repeat(101);
   await recovered(await submit(emoji),emoji,'101 astral Unicode codepoints');
   const [cleared]=await Promise.all([
     page.waitForResponse(response=>response.request().isNavigationRequest()&&
       response.request().method()==='GET'&&new URL(response.url()).pathname==='/_preview'&&hasQuery(response.url(),filters)),
     page.waitForNavigation({waitUntil:'load'}),
     page.getByRole('link',{name:'Limpar busca',exact:true}).click(),
   ]);
   check(cleared.status()===200&&hasQuery(page.url(),filters)&&await field().inputValue()===''&&
     await rows().count()===50&&await error().count()===0,
     'clearing the emoji error uses a real link and restores the first queue page with both filters');

   const boundary='😀'.repeat(200);
   await recovered(await submit(boundary),boundary,'the200-codepoint and800-byte recovery boundary');
   await hardDenied(await submit('😀'.repeat(201)),'201 astral codepoints beyond the recovery boundary');
   const fresh=await page.goto(queryUrl(filters));
   check(fresh.status()===200&&await field().inputValue()===''&&await error().count()===0&&await rows().count()===50,
     'a subsequent visit without q has an empty search field and no retained recovery message');

   const markup='<img data-search-recovery-probe="literal" src=x>'.padEnd(101,'x');
   await recovered(await submit(markup),markup,'101 codepoints containing markup-like text');
   check(await page.locator('[data-search-recovery-probe]').count()===0&&
     await page.evaluate(term=>!document.body.textContent.includes(term),markup),
     'the markup is escaped inside the input and is neither a new element nor echoed elsewhere in visible text');
   for(const [name,size] of [['desktop',{width:1440,height:1000}],['mobile',{width:390,height:844}]]){
     await page.setViewportSize(size);
     check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
       'the full retained input and inline error fit the '+name+' viewport');
     await page.getByTestId('preview-queue').screenshot({path:dir+'/queue-search-recovery-'+name+'.png'});
   }
   const markupCorrected=await submit(searched.q);
   check(markupCorrected.status()===200&&hasQuery(page.url(),searched)&&
     JSON.stringify(await ids())===JSON.stringify(first)&&await error().count()===0,
     'the escaped error remains editable and corrects to the exact real filtered results');
   await page.setViewportSize({width:1440,height:1000});
   await page.getByTestId('preview-queue').screenshot({path:dir+'/queue-search-recovery-corrected.png'});

   const structural=[
     ['array-valued search',queue+'?q%5B%5D='+encodeURIComponent(ascii)],
     ['an internal control character',queryUrl({...filters,q:'A'.repeat(50)+'\n'+'A'.repeat(50)})],
     ['an unknown query key',queryUrl({...filters,q:ascii,unexpected:'1'})],
     ['a malformed base cursor',queryUrl({...filters,q:ascii,cursor:'not-a-valid-cursor'})],
   ];
   for(const [label,url] of structural)await hardDenied(await context.request.get(url),label);
   await hardDenied(await context.request.get(historyUrl+'?'+new URLSearchParams({q:ascii})),
     'an overlong search on a project GET');

   // These are exact fields from a valid current project, including its real
   // CSRF and current CAS. Only the GET search selector is made invalid.
   const current=await page.goto(historyUrl);
   if(current.status()!==200)throw Error('queue search recovery: valid current project unavailable for the POST control');
   const edit=page.locator('form:has(input[name="expected_revision"])');
   const payload={};
   for(const name of ['_token','product','expected_revision','brand','headline','description','call_to_action','benefits']){
     payload[name]=await edit.locator('[name="'+name+'"]').inputValue();
   }
   await hardDenied(await context.request.post(historyUrl+'?'+new URLSearchParams({q:ascii}),
     {form:payload,maxRedirects:0}),'an otherwise valid project POST with an overlong search query');
   check(snapshot()===before,
     'all principals, projects, immutable versions/HTML/digests, reviews and audit rows remain unchanged');
 } finally {
   await context.close();
 }
 return {
   async verifyRevocation(){
     // The parent intentionally revokes the author later. Take that state as
     // the new baseline rather than confusing it with an effect of recovery.
     const revokedBefore=snapshot();
     const response=await a.request.get(recoveryUrl),text=await response.text();
     check(response.status()===403&&!text.includes('data-testid="queue-filters"')&&
       !text.includes('data-testid="queue-search-error"')&&!text.includes('data-project=')&&!text.includes(ascii),
       'revocation denies the same cursor and search URL that previously rendered the recovery page');
     check(snapshot()===revokedBefore,'the refused recovery request leaves the revoked persisted state unchanged');
     console.log('QUEUE SEARCH RECOVERY HTTP PASS: '+additionalChecks+' new checks; '+baselineChecks+' existing checks retained.');
   },
 };
};
