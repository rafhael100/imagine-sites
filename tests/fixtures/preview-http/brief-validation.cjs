const crypto=require('crypto');

module.exports=async({ctx,o,base,dir,ok})=>{
 const context=await ctx('author',{javaScriptEnabled:false});
 context.setDefaultTimeout(20000);
 context.setDefaultNavigationTimeout(20000);
 const page=await context.newPage();
 const fields=['brand','headline','description','call_to_action','benefits'];
 let blankLineChecks=0;
 const blankLineOk=(condition,label)=>{ok(condition,'benefit blank lines: '+label);blankLineChecks++;};
 let limitMessageChecks=0;
 const limitMessageOk=(condition,label)=>{ok(condition,'limit messages: '+label);limitMessageChecks++;};
 let multilineChecks=0;
 const multilineOk=(condition,label)=>{ok(condition,'multiline briefing: '+label);multilineChecks++;};
 const field=name=>page.locator('#'+name);
 const fill=async values=>{for(const name of fields)await field(name).fill(values[name]);};
 const submit=async name=>{
   const action=await page.locator('form:has([name=brand])').getAttribute('action');
   const target=new URL(action,base).toString();
   const [response]=await Promise.all([
     page.waitForResponse(r=>r.request().isNavigationRequest()&&r.request().method()==='POST'&&r.url()===target),
     page.waitForNavigation({waitUntil:'load'}),
     page.getByRole('button',{name}).click(),
   ]);
   await page.waitForLoadState('load');
   return response;
 };
 ok((await page.goto(base+'/_preview')).status()===200,'briefing boundary journey starts in the authenticated workspace');
 for(const name of fields){
   ok(await field(name).getAttribute('maxlength')===null&&
      (await field(name).getAttribute('aria-describedby')).includes('help-'+name)&&
      await page.locator('#help-'+name).isVisible(),
      'briefing '+name+' exposes the server limit without a UTF16 browser limit');
 }
 const boundary={brand:'😀'.repeat(120),headline:'🌿'.repeat(180),description:'🪴'.repeat(600),
   call_to_action:'🚀'.repeat(80),benefits:Array(8).fill('💡'.repeat(180)).join('\n')};
 await page.locator('#product').selectOption('onepage');
 await fill(boundary);
 ok((await submit(/^Criar demonstração/)).status()===302,
    'the actual no-JavaScript form accepts every briefing field at its astral Unicode limit');
 const project=page.url();
 ok(new URL(project).pathname.startsWith('/_preview/projects/')&&
    await page.locator('input[name=expected_revision]').inputValue()==='1',
    'the boundary form creates exactly the first revision');
 for(const name of fields)ok(await field(name).inputValue()===boundary[name],
    'the saved briefing preserves all Unicode codepoints in '+name);
 const first=await context.request.get(project+'/versions/1/html');
 const firstBytes=await first.body();
 const digest=crypto.createHash('sha256').update(firstBytes).digest('hex');
 ok(first.status()===200&&await page.locator('[data-version="1"] code').textContent()==='SHA-256: '+digest,
    'the boundary revision has the exact stored HTML digest');

 const oversized='😀'.repeat(121);
 await field('brand').fill(oversized);
 ok((await submit(/^Gerar nova versão privada/)).status()===422,
    'one codepoint over the brand limit reaches server validation');
 ok(await field('brand').inputValue()===oversized&&await field('description').inputValue()===boundary.description&&
    await field('brand').getAttribute('aria-invalid')==='true'&&await page.locator('#error-brand').isVisible(),
    'valid over-limit text remains intact with an associated field error');
 const verifyLimitMessage=async(name,values,message)=>{
   const attempt=await Promise.all(fields.map(async other=>({name:other,
     value:await field(other).inputValue(),invalid:await field(other).getAttribute('aria-invalid')})));
   limitMessageOk((await page.locator('#error-'+name).textContent()).trim()===message&&
     attempt.every(item=>item.value===values[item.name]&&
       (item.name===name?item.invalid==='true':item.invalid!=='true'))&&
     await page.locator('input[name=expected_revision]').inputValue()==='1'&&
     await page.locator('[data-version]').count()===1,
     name+' has its exact Portuguese maximum message, only that field is invalid, and the complete attempt and saved revision1 remain intact');
 };
 await verifyLimitMessage('brand',{...boundary,brand:oversized},'Use até 120 caracteres no campo marca fictícia.');
 // The existing brand rejection is reused. Each additional field has one
 // isolated negative POST on this same project, before the existing no-append
 // and immutable-HTML assertions below. No extra successful edit is introduced.
 const otherLimits=[
   ['headline','🌿'.repeat(181),'Use até 180 caracteres no campo chamada principal.'],
   ['description','🪴'.repeat(601),'Use até 600 caracteres no campo descrição.'],
   ['call_to_action','🚀'.repeat(81),'Use até 80 caracteres no campo chamada do botão.'],
   // Interior whitespace survives TrimStrings on the entire textarea. After
   // line parsing there would be two short benefits, so only the raw limit fails.
   ['benefits','Primeiro benefício\n'+' '.repeat(1501)+'\nSegundo benefício','Use até 1500 caracteres no campo benefícios.'],
 ];
 for(const [name,value,message] of otherLimits){
   const values={...boundary,[name]:value};
   await fill(values);
   const rejected=await submit(/^Gerar nova versão privada/);
   if(rejected.status()!==422)throw new Error('Expected isolated briefing maximum validation for '+name);
   await verifyLimitMessage(name,values,message);
 }

 const normal={brand:'Estúdio Aurora — fictício',headline:'Projetos claros para começar bem',
   description:'Conteúdo sintético para verificar a correção do briefing sem perder os outros campos.',
   call_to_action:'Conheça a proposta',benefits:'Atendimento organizado\nInformações claras'};
 const invalid={brand:'\0Marca fictícia',headline:'Chamada fictícia\0',description:'Texto\0fictício',
   call_to_action:'\0Conheça a proposta\0',benefits:'Primeiro benefício\n\0Segundo benefício'};
 for(const name of fields){
   // Send exact encoded bytes: input automation or a browser can sanitize NUL.
   // The authenticated HTTP response is then rendered in the same browser.
   const encoded=new URLSearchParams({...normal,[name]:invalid[name],product:'onepage',expected_revision:'1',
     _token:await page.locator('input[name=_token]').first().inputValue()}).toString();
   ok(encoded.includes('%00')&&new URLSearchParams(encoded).get(name)===invalid[name],
      'the malformed '+name+' is explicitly encoded with its exact NUL bytes');
   const rejected=await context.request.post(project,{data:encoded,
     headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
   ok(rejected.status()===422,
      'NUL in '+name+' is rejected through the real web middleware before storage');
   await page.setContent(await rejected.text(),{waitUntil:'load'});
   const preserved=await Promise.all(fields.map(async other=>[other,await field(other).inputValue()]));
   ok(preserved.every(([other,value])=>value===(other===name?'':normal[other]))&&
      await field(name).getAttribute('aria-invalid')==='true'&&
      (await page.locator('#error-'+name).textContent()).includes('Digite novamente')&&
      await page.locator('input[name=expected_revision]').inputValue()==='1',
      'only the malformed '+name+' is cleared; the rest of the briefing and revision are retained');
   if(name==='brand'){
     await page.setViewportSize({width:1440,height:1000});
     await page.getByRole('alert').scrollIntoViewIfNeeded();
     await page.screenshot({path:dir+'/brief-validation-desktop.png'});
     await page.setViewportSize({width:390,height:844});
     await page.getByRole('alert').scrollIntoViewIfNeeded();
     await page.screenshot({path:dir+'/brief-validation-mobile.png'});
     ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
        'briefing validation and Unicode helper text fit the mobile viewport');
     await page.setViewportSize({width:1280,height:900});
   }
 }
 const token=await page.locator('input[name=_token]').first().inputValue();
 const malformed=new URLSearchParams({...normal,product:'onepage',expected_revision:'1',_token:token,headline:'MALFORMED_UTF8_PLACEHOLDER'})
   .toString().replace('MALFORMED_UTF8_PLACEHOLDER','%C3%28');
 const invalidUtf8=await context.request.post(project,{data:malformed,
   headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
 const invalidBody=await invalidUtf8.text();
 ok(invalidUtf8.status()===422&&invalidBody.includes('Digite novamente este campo.')&&
    /name="headline"[^>]*value=""/.test(invalidBody)&&invalidBody.includes(normal.brand),
    'malformed UTF8 is rejected with an empty damaged field and the other text preserved');
 // Put the existing eight-benefit correction into this rejected attempt so
 // the later native POST needs to change only description. The raw request
 // deliberately distinguishes LF, CRLF and CR before HTML parsing normalizes
 // textarea values to LF; a native form submission will encode them as CRLF.
 const realBenefits=['Atendimento organizado','Informações\u00a0claras',
   '\u00a0Contato preservado\u00a0','\u2003Prazos combinados\u2003','Planejamento cuidadoso',
   'Conteúdo com\u2003espaço interno','Orientação compreensível','Próximos passos definidos'];
 const withBlankLines=[realBenefits[0],'\u00a0',realBenefits[1],realBenefits[2],'\u2003',
   realBenefits[3],realBenefits[4],'\u00a0\u2003',realBenefits[5],realBenefits[6],realBenefits[7]].join('\n');
 const multilineRaw={brand:'Marca fictícia\nLinha preservada',
   headline:'Chamada preservada\r\n</textarea><b data-multiline-probe>texto</b>',
   call_to_action:'Conheça\rA proposta'};
 const multilineDom=Object.fromEntries(Object.entries(multilineRaw)
   .map(([name,value])=>[name,value.replace(/\r\n?/g,'\n')]));
 const multilineLabels={brand:'Marca fictícia',headline:'Chamada principal',call_to_action:'Chamada do botão'};
 const multilineAttempt={...normal,...multilineRaw,description:'D'.repeat(601),benefits:withBlankLines};
 const multilineEncoded=new URLSearchParams({...multilineAttempt,product:'onepage',expected_revision:'1',
   _token:await page.locator('input[name=_token]').first().inputValue()}).toString();
 multilineOk(Object.entries(multilineRaw).every(([name,value])=>new URLSearchParams(multilineEncoded).get(name)===value)&&
   multilineEncoded.includes('%0A')&&multilineEncoded.includes('%0D%0A')&&multilineEncoded.includes('%0D'),
   'the real rejected request explicitly carries distinct LF, CRLF and CR values');
 const multilineRejected=await context.request.post(project,{data:multilineEncoded,
   headers:{'Content-Type':'application/x-www-form-urlencoded'},maxRedirects:0});
 await page.setContent(await multilineRejected.text(),{waitUntil:'load'});
 const multilinePreserved=await Promise.all(fields.map(async name=>({name,value:await field(name).inputValue(),
   invalid:await field(name).getAttribute('aria-invalid')})));
 const namedTextareas=await Promise.all(Object.entries(multilineLabels).map(async([name,label])=>
   await page.locator('textarea#'+name).count()===1&&
   await page.getByLabel(label,{exact:true}).getAttribute('id')===name));
 const multilineWidths=[];
 for(const [width,height,suffix] of [[1440,1000,'desktop'],[390,844,'mobile']]){
   await page.setViewportSize({width,height});
   const form=page.getByTestId('briefing-form');
   await form.scrollIntoViewIfNeeded();
   multilineWidths.push(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)&&
     await form.evaluate(node=>node.scrollWidth<=node.clientWidth));
   await form.screenshot({path:dir+'/brief-multiline-'+suffix+'.png'});
 }
 await page.setViewportSize({width:1280,height:900});
 multilineOk(multilineRejected.status()===422&&namedTextareas.every(Boolean)&&multilineWidths.every(Boolean)&&
   multilinePreserved.every(({name,value,invalid})=>value===({...multilineAttempt,...multilineDom})[name]&&
     (name==='description'?invalid==='true':invalid!=='true'))&&
   (await page.locator('#error-description').textContent()).trim()==='Use até 600 caracteres no campo descrição.'&&
   await page.locator('[data-multiline-probe]').count()===0&&
   await page.locator('input[name=expected_revision]').inputValue()==='1'&&
   await page.locator('[data-version]').count()===1,
   '422 preserves the three labelled multiline controls and literal markup with DOM LF, only description invalid, CAS1 and no horizontal overflow');
 ok((await context.request.get(project+'/versions/2/html')).status()===403&&
    crypto.createHash('sha256').update(await (await context.request.get(project+'/versions/1/html')).body()).digest('hex')===digest,
    'invalid briefing submissions create no revision and leave the original bytes unchanged');
 ok([403,404].includes((await o.request.get(project)).status()),
    'the new Unicode project remains isolated from another company');
 // Reuse the already planned correction/revision2. Only wholly blank lines
 // disappear; Unicode spaces belonging to real benefits keep their bytes.
 await field('description').fill(normal.description);
 const corrected=await submit(/^Gerar nova versão privada/);
 ok(corrected.status()===302&&
    await page.locator('input[name=expected_revision]').inputValue()==='2'&&
    await field('headline').inputValue()===multilineDom.headline,
    'correcting the briefing succeeds with the same protected browser session');
 const correctedPayload=new URLSearchParams(corrected.request().postData());
 const correctedMultiline=await Promise.all(Object.keys(multilineDom).map(async name=>
   await field(name).inputValue()===multilineDom[name]));
 multilineOk(Object.entries(multilineDom).every(([name,value])=>correctedPayload.get(name)===value.replace(/\n/g,'\r\n'))&&
   correctedPayload.get('description')===normal.description&&correctedMultiline.every(Boolean),
   'correcting only description resubmits all three controls through the native no-JavaScript form as CRLF and returns the saved DOM LF values');
 const sentBenefits=new URLSearchParams(corrected.request().postData()).get('benefits');
 blankLineOk(typeof sentBenefits==='string'&&sentBenefits.replace(/\r\n/g,'\n')===withBlankLines&&
   await field('benefits').inputValue()===realBenefits.join('\n'),
   'the real no-JavaScript POST sends NBSP and EM SPACE blank lines but the saved form contains exactly eight intact real benefits');
 const preview=await context.newPage();
 const second=await preview.goto(project+'/versions/2/html');
 const secondBytes=await second.body();
 const secondDigest=crypto.createHash('sha256').update(secondBytes).digest('hex');
 blankLineOk(second.status()===200&&
   JSON.stringify(await preview.locator('.benefits li').allTextContents())===JSON.stringify(realBenefits)&&
   await page.locator('[data-version="2"] code').textContent()==='SHA-256: '+secondDigest,
   'the stored HTML has the exact revision2 digest and only eight benefits, preserving Unicode spaces at real-text edges and inside lines');
 const nineRealBenefits=withBlankLines+'\nNono benefício real';
 await field('benefits').fill(nineRealBenefits);
 const ninth=await submit(/^Gerar nova versão privada/);
 blankLineOk(ninth.status()===422&&await field('benefits').getAttribute('aria-invalid')==='true'&&
   (await page.locator('#error-benefits').textContent()).includes('Informe de um a oito benefícios, um por linha.')&&
   await field('benefits').inputValue()===nineRealBenefits&&
   await field('headline').inputValue()===multilineDom.headline&&
   await page.locator('input[name=expected_revision]').inputValue()==='2',
   'nine real benefits still receive the specific count error with the complete attempt and CAS2 preserved');
 const absentThird=await context.request.get(project+'/versions/3/html');
 const unchangedFirst=await context.request.get(project+'/versions/1/html');
 const unchangedSecond=await context.request.get(project+'/versions/2/html');
 const fresh=await page.goto(project);
 blankLineOk(absentThird.status()===403&&unchangedFirst.status()===200&&unchangedSecond.status()===200&&
   (await unchangedFirst.body()).equals(firstBytes)&&(await unchangedSecond.body()).equals(secondBytes)&&
   fresh.status()===200&&await page.locator('input[name=expected_revision]').inputValue()==='2'&&
   await page.locator('[data-version]').count()===2&&await field('benefits').inputValue()===realBenefits.join('\n'),
   'the rejected ninth benefit appends no version and leaves both immutable HTML bodies and the eight-item saved briefing unchanged');
 const freshMultiline=await Promise.all(Object.keys(multilineDom).map(async name=>
   await page.locator('textarea#'+name).count()===1&&await field(name).inputValue()===multilineDom[name]));
 multilineOk(fresh.status()===200&&freshMultiline.every(Boolean)&&
   await field('description').inputValue()===normal.description&&
   await page.locator('[data-multiline-probe]').count()===0,
   'a fresh GET reopens the saved multiline fields and literal markup after the unrelated ninth-benefit refusal');
 console.log('BENEFIT BLANK LINES HTTP PASS: '+blankLineChecks+' new checks; 447 existing checks retained.');
 console.log('LIMIT MESSAGES HTTP PASS: '+limitMessageChecks+' new checks; 451 existing checks retained.');
 console.log('MULTILINE BRIEF FIELDS HTTP PASS: '+multilineChecks+' new checks; 456 existing checks retained.');
 await context.close();
};
