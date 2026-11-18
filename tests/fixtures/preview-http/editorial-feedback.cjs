module.exports=async({a,e,o,p,ep,base,dir,historyUrl,ok})=>{
 const note='  Ajuste a chamada principal.\nExplique o benefício com texto literal <strong data-feedback-probe="literal">sem formatação</strong> e atenção ao público 漢😀.  ';
 const duplicateNote='Orientação fictícia de outra aba que deve ser preservada.';
 await ep.goto(historyUrl);
 const currentForm=()=>ep.locator('form.review[data-review-revision="62"]');
 const digest=await currentForm().getAttribute('data-review-digest');
 const reviewUrl=new URL(await currentForm().getAttribute('action'),base).toString();
 await currentForm().locator('[name=decision]').selectOption('changes_requested');
 const tooLong='漢'.repeat(2001);
 await currentForm().locator('textarea[name=note]').fill(tooLong);
 const invalid=ep.waitForResponse(r=>r.url()===reviewUrl&&r.request().method()==='POST');
 await currentForm().getByRole('button',{name:'Registrar revisão editorial'}).click();
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
 const staleForm=stale.locator('form.review[data-review-revision="62"]');
 await staleForm.locator('[name=decision]').selectOption('changes_requested');
 await staleForm.locator('textarea[name=note]').fill(duplicateNote);
 await currentForm().locator('textarea[name=note]').fill(note);
 await currentForm().getByRole('button',{name:'Registrar revisão editorial'}).click();
 await ep.waitForURL(historyUrl);
 const guidance=ep.getByTestId('current-editorial-guidance');
 ok(await guidance.getAttribute('data-review-revision')==='62'&&
    await guidance.getAttribute('data-review-digest')===digest&&
    (await guidance.locator('[data-review-note]').allTextContents()).includes(note),
    'reviewer records exact whitespace, Unicode and version/digest guidance through Laravel');
 ok(await ep.locator('[data-feedback-probe]').count()===0,
    'guidance markup is escaped instead of becoming a DOM element');
 const conflict=stale.waitForResponse(r=>r.url()===reviewUrl&&r.request().method()==='POST');
 await staleForm.getByRole('button',{name:'Registrar revisão editorial'}).click();
 ok((await conflict).status()===409,'another tab receives409 for an already-recorded immutable review');
 const retained=stale.getByTestId('preserved-review');
 ok(await retained.getAttribute('data-review-revision')==='62'&&
    await retained.getAttribute('data-review-digest')===digest&&
    (await retained.textContent()).includes(duplicateNote)&&await retained.locator('button').count()===0&&
    await stale.locator('form.review[data-review-revision="62"]').count()===0,
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

 // A historical review is still permitted and remains attached to version61.
 await ep.goto(historyUrl+'?before_revision=62');
 const historical=ep.locator('form.review[data-review-revision="61"]');
 await historical.locator('[name=decision]').selectOption('approved_internal');
 await historical.locator('textarea[name=note]').fill(' \n\u2003');
 await historical.getByRole('button',{name:'Registrar revisão editorial'}).click();
 ok((await ep.getByTestId('current-editorial-guidance').locator('[data-review-note]').allTextContents()).includes(note)&&
    await ep.getByTestId('current-editorial-guidance').getAttribute('data-review-revision')==='62',
    'optional historical approval note does not replace current adjustment guidance');

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
 const unicodeForm=ep.locator('form.review[data-review-revision="63"]');
 await unicodeForm.locator('[name=decision]').selectOption('changes_requested');
 await unicodeForm.locator('textarea[name=note]').fill(boundary);
 await unicodeForm.getByRole('button',{name:'Registrar revisão editorial'}).click();
 await ep.waitForURL(historyUrl);
 ok((await ep.getByTestId('current-editorial-guidance').locator('[data-review-note]').allTextContents()).includes(boundary),
    '2000 astral Unicode codepoints survive the real form, proxy and database');
 await ep.setViewportSize({width:390,height:844});
 ok(await ep.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),
    'unbroken 2000-codepoint guidance remains within the mobile viewport');
};
