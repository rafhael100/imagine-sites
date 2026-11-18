module.exports=async({ctx,a,e,o,p,ep,base,dir,historyUrl,ok})=>{
 let accessibilityChecks=0;
 const accessibilityCheck=(value,label)=>{ok(value,'review accessibility: '+label);accessibilityChecks++;};
 const note='  Ajuste a chamada principal.\nExplique o benefício com texto literal <strong data-feedback-probe="literal">sem formatação</strong> e atenção ao público 漢😀.  ';
 const duplicateNote='Orientação fictícia de outra aba que deve ser preservada.';
 const tooLong='漢'.repeat(2001);

 // Versions 61 and 62 are both still reviewable here. Keep the historical
 // validation response open until its already-planned approval below.
 const historicalContext=await ctx('editor',{javaScriptEnabled:false});
 const historicalPage=await historicalContext.newPage();
 await historicalPage.goto(historyUrl);
 const historicalForm=()=>historicalPage.getByRole('form',{name:'Versão 61',exact:true});
 const historicalDecision=()=>historicalPage.getByLabel('Parecer editorial da versão 61',{exact:true});
 const historicalNote=()=>historicalPage.getByLabel('Orientação editorial da versão 61',{exact:true});
 const otherForm=()=>historicalPage.getByRole('form',{name:'Versão 62',exact:true});
 const otherState=async()=>({
   action:await otherForm().getAttribute('action'),
   digest:await otherForm().locator('input[name=sha256]').inputValue(),
   decision:await historicalPage.getByLabel('Parecer editorial da versão 62',{exact:true}).inputValue(),
   note:await historicalPage.getByLabel('Orientação editorial da versão 62',{exact:true}).inputValue(),
   decisionInvalid:await historicalPage.getByLabel('Parecer editorial da versão 62',{exact:true}).getAttribute('aria-invalid'),
   noteInvalid:await historicalPage.getByLabel('Orientação editorial da versão 62',{exact:true}).getAttribute('aria-invalid'),
 });
 accessibilityCheck(await historicalForm().count()===1&&await otherForm().count()===1&&
   await historicalPage.getByRole('heading',{name:'Versão 61',level:3,exact:true}).isVisible()&&
   await historicalPage.getByRole('heading',{name:'Versão 62',level:3,exact:true}).isVisible()&&
   await historicalPage.getByRole('button',{name:'Registrar revisão editorial da versão 61',exact:true}).count()===1&&
   await historicalPage.getByRole('button',{name:'Registrar revisão editorial da versão 62',exact:true}).count()===1,
   'two eligible versions have independently discoverable exact form, heading and submit names');
 const untouched=await otherState();
 const historicalDigest=await historicalForm().locator('input[name=sha256]').inputValue();
 const historicalUrl=new URL(await historicalForm().getAttribute('action'),base).toString();
 await historicalDecision().selectOption('approved_internal');
 await historicalNote().fill(tooLong);
 const [historicalInvalid,historicalLoaded]=await Promise.all([
   historicalPage.waitForResponse(response=>response.request().isNavigationRequest()&&response.request().method()==='POST'&&response.url()===historicalUrl),
   historicalPage.waitForNavigation({waitUntil:'load'}),
   historicalPage.getByRole('button',{name:'Registrar revisão editorial da versão 61',exact:true}).click(),
 ]);
 const invalidPayload=new URLSearchParams(historicalInvalid.request().postData());
 accessibilityCheck(historicalInvalid.status()===422&&historicalLoaded!==null&&historicalLoaded.status()===422&&
   new URL(historicalUrl).pathname.endsWith('/versions/61/review')&&invalidPayload.get('sha256')===historicalDigest&&
   invalidPayload.get('decision')==='approved_internal'&&invalidPayload.get('note')===tooLong&&
   await historicalPage.getByRole('alert',{name:'Confira o parecer da versão 61',exact:true}).isVisible(),
   'the exact historical controls submit their own digest and receive the named version 61 validation response');
 accessibilityCheck(await historicalNote().inputValue()===tooLong&&await historicalDecision().inputValue()==='approved_internal'&&
   await historicalForm().locator('input[name=sha256]').inputValue()===historicalDigest&&
   await historicalNote().getAttribute('aria-invalid')==='true'&&
   (await historicalNote().getAttribute('aria-describedby')).split(/\s+/).includes('error-note-61')&&
   await historicalDecision().getAttribute('aria-invalid')===null&&
   await historicalForm().locator('[aria-invalid="true"]').count()===1&&
   await otherForm().locator('[aria-invalid="true"]').count()===0&&JSON.stringify(await otherState())===JSON.stringify(untouched),
   'only the selected guidance is invalid; its full text, decision and digest survive while version 62 stays untouched');
 for(const [name,width,height] of [['desktop',1440,1000],['mobile',390,844]]){
   await historicalPage.setViewportSize({width,height});
   accessibilityCheck(await historicalPage.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)&&
     await historicalForm().evaluate(node=>node.scrollWidth<=node.clientWidth),
     'the preserved historical review and its version-specific controls fit the '+name+' viewport');
   await historicalForm().screenshot({path:dir+'/review-accessibility-'+name+'.png'});
 }

 await ep.goto(historyUrl);
 const currentForm=()=>ep.getByRole('form',{name:'Versão 62',exact:true});
 const digest=await currentForm().getAttribute('data-review-digest');
 const reviewUrl=new URL(await currentForm().getAttribute('action'),base).toString();
 await currentForm().locator('[name=decision]').selectOption('changes_requested');
 await currentForm().locator('textarea[name=note]').fill(tooLong);
 const invalid=ep.waitForResponse(r=>r.url()===reviewUrl&&r.request().method()==='POST');
 await currentForm().getByRole('button',{name:'Registrar revisão editorial da versão 62',exact:true}).click();
 ok((await invalid).status()===422,'oversized guidance gets a real422 validation response');
 ok(await ep.getByTestId('preserved-review').getAttribute('data-review-revision')==='62'&&
    await ep.getByTestId('preserved-review').getAttribute('data-review-digest')===digest&&
    await currentForm().locator('textarea[name=note]').inputValue()===tooLong&&
    await currentForm().locator('[name=decision]').inputValue()==='changes_requested',
    '422 retains the entire guidance and decision on the exact reviewed version');
 const noGuidance=await e.request.post(reviewUrl,{form:{
   _token:await currentForm().locator('input[name=_token]').getAttribute('value'),
   sha256:digest,decision:'changes_requested',note:' \n\u2003\u3000',
 },maxRedirects:0});
 ok(noGuidance.status()===422,'Unicode whitespace alone cannot request editorial changes');

 // Both tabs use the same reviewer identity and version. Only the first can
 // record its immutable decision; the second must retain its unsaved text.
 const stale=await e.newPage();await stale.goto(historyUrl);
 const staleForm=stale.getByRole('form',{name:'Versão 62',exact:true});
 await staleForm.locator('[name=decision]').selectOption('changes_requested');
 await staleForm.locator('textarea[name=note]').fill(duplicateNote);
 await currentForm().locator('textarea[name=note]').fill(note);
 await currentForm().getByRole('button',{name:'Registrar revisão editorial da versão 62',exact:true}).click();
 await ep.waitForURL(historyUrl);
 const guidance=ep.getByTestId('current-editorial-guidance');
 ok(await guidance.getAttribute('data-review-revision')==='62'&&
    await guidance.getAttribute('data-review-digest')===digest&&
    (await guidance.locator('[data-review-note]').allTextContents()).includes(note),
    'reviewer records exact whitespace, Unicode and version/digest guidance through Laravel');
 ok(await ep.locator('[data-feedback-probe]').count()===0,
    'guidance markup is escaped instead of becoming a DOM element');
 const conflict=stale.waitForResponse(r=>r.url()===reviewUrl&&r.request().method()==='POST');
 await staleForm.getByRole('button',{name:'Registrar revisão editorial da versão 62',exact:true}).click();
 ok((await conflict).status()===409,'another tab receives409 for an already-recorded immutable review');
 const retained=stale.getByRole('region',{name:'Parecer não registrado da versão 62',exact:true});
 ok(await retained.getAttribute('data-review-revision')==='62'&&
    await retained.getAttribute('data-review-digest')===digest&&
    (await retained.textContent()).includes(duplicateNote)&&await retained.locator('button').count()===0&&
    await stale.getByRole('form',{name:'Versão 62',exact:true}).count()===0&&
    await stale.getByRole('button',{name:'Registrar revisão editorial da versão 62',exact:true}).count()===0,
    '409 preserves unsaved text without retargeting or offering duplicate submission');
 await stale.close();

 await p.goto(historyUrl+'?before_revision=41&compare_from=1&compare_to=60');
 const authorGuidance=p.getByTestId('current-editorial-guidance');
 ok(await p.locator('[data-version="62"]').count()===0&&
    await p.locator('[data-version="40"]').isVisible()&&
    await authorGuidance.getAttribute('data-review-revision')==='62'&&
    (await authorGuidance.locator('[data-review-note]').allTextContents()).includes(note),
    'author sees current guidance while viewing older history and comparison');
 ok(await p.locator('input[name=expected_revision]').inputValue()==='62'&&
    await p.locator('[data-feedback-probe]').count()===0,
    'guidance does not replace the edit head or execute its literal markup');
 await p.setViewportSize({width:1440,height:1000});
 await authorGuidance.screenshot({path:dir+'/editorial-guidance-desktop.png'});
 await p.setViewportSize({width:390,height:844});
 ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
    'editorial guidance and historical context fit the mobile viewport');
 await authorGuidance.screenshot({path:dir+'/editorial-guidance-mobile.png'});
 const foreign=await o.request.get(historyUrl);
 ok([403,404].includes(foreign.status())&&!(await foreign.text()).includes('Ajuste a chamada principal.'),
    'another company cannot read the private editorial guidance');

 // Correct the still-open version 61 response with its own CSRF and digest.
 // This is the same historical approval already exercised by this journey.
 await historicalNote().fill(' \n\u2003');
 const [historicalSaved,historicalSavedPage]=await Promise.all([
   historicalPage.waitForResponse(response=>response.request().isNavigationRequest()&&response.request().method()==='POST'&&response.url()===historicalUrl),
   historicalPage.waitForNavigation({waitUntil:'load'}),
   historicalPage.getByRole('button',{name:'Registrar revisão editorial da versão 61',exact:true}).click(),
 ]);
 const savedPayload=new URLSearchParams(historicalSaved.request().postData());
 const historicalRecord=historicalPage.getByTestId('editorial-review-history').locator('[data-review-revision="61"]');
 accessibilityCheck(historicalSaved.status()===302&&historicalSavedPage!==null&&historicalSavedPage.status()===200&&
   savedPayload.get('sha256')===historicalDigest&&savedPayload.get('decision')==='approved_internal'&&
   savedPayload.get('note').replace(/\r\n/g,'\n')===' \n\u2003'&&await historicalForm().count()===0&&
   await historicalRecord.getAttribute('data-review-digest')===historicalDigest&&
   (await historicalRecord.textContent()).includes('Aprovada internamente')&&
   (await historicalRecord.textContent()).includes('Este parecer foi registrado sem observação.'),
   'correcting the retained historical form records its original version and digest without retargeting another version');
 ok((await historicalPage.getByTestId('current-editorial-guidance').locator('[data-review-note]').allTextContents()).includes(note)&&
    await historicalPage.getByTestId('current-editorial-guidance').getAttribute('data-review-revision')==='62',
    'optional historical approval note does not replace current adjustment guidance');
 await historicalContext.close();

 await p.getByLabel('Chamada principal').fill('Orientação aplicada na próxima versão fictícia');
 await p.getByRole('button',{name:'Gerar nova versão privada'}).click();
 await p.waitForURL(historyUrl);
 ok(await p.locator('input[name=expected_revision]').inputValue()==='63'&&
    await p.getByTestId('current-editorial-guidance').getAttribute('data-review-revision')==='63'&&
    await p.getByTestId('current-editorial-guidance').locator('[data-review-note]').count()===0,
    'new head63 does not inherit version62 guidance or internal decision');
 const savedHistory=p.getByTestId('editorial-review-history').locator('[data-review-revision="62"]');
 ok((await savedHistory.locator('[data-review-note]').allTextContents()).includes(note)&&
    await savedHistory.getAttribute('data-review-digest')===digest,
    'previous guidance remains visible in immutable history after a new version');

 const boundary='😀'.repeat(2000);
 await ep.goto(historyUrl);
 const unicodeForm=ep.getByRole('form',{name:'Versão 63',exact:true});
 await unicodeForm.locator('[name=decision]').selectOption('changes_requested');
 await unicodeForm.locator('textarea[name=note]').fill(boundary);
 await unicodeForm.getByRole('button',{name:'Registrar revisão editorial da versão 63',exact:true}).click();
 await ep.waitForURL(historyUrl);
 ok((await ep.getByTestId('current-editorial-guidance').locator('[data-review-note]').allTextContents()).includes(boundary),
    '2000 astral Unicode codepoints survive the real form, proxy and database');
 await ep.setViewportSize({width:390,height:844});
 ok(await ep.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
    'unbroken 2000-codepoint guidance remains within the mobile viewport');
 console.log('REVIEW ACCESSIBILITY HTTP PASS: '+accessibilityChecks+' new checks; 433 existing checks retained.');
};
