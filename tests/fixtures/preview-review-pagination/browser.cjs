const crypto = require('crypto');
const path = require('path');

// Runs after the original queue-count journeys. Setup stays in the guarded
// disposable seed; the subsequent review and version use real browser POSTs.
module.exports = async ({ctx, base, dir, docker, ok}) => {
 const baselineChecks = 394;
 let newChecks = 0;
 const check = (value, label) => { ok(value, 'review pagination: ' + label); newChecks++; };
 const mainId = 'a1100000-0000-4000-8000-000000000001';
 const emptyId = 'a1100000-0000-4000-8000-000000000002';
 const foreignId = 'b1100000-0000-4000-8000-000000000001';
 const projectUrl = id => base + '/_preview/projects/' + id;
 const mainUrl = projectUrl(mainId);
 const sql = query => docker(['exec', '-T', 'db', 'psql', '-U', 'postgres', '-d', 'sites_preview_http_ci',
   '-X', '-v', 'ON_ERROR_STOP=1', '-Atc', query]).trim();
 const snapshot = (excludeMain = false) => sql(['principals', 'projects', 'versions', 'reviews', 'audit'].map(table => {
   const where = excludeMain && table !== 'principals'
     ? " WHERE r." + (table === 'projects' ? 'id' : 'project_id') + "<>'" + mainId + "'::uuid" : '';
   return "SELECT '" + table + "' AS table_name,count(*) AS row_count," +
     "encode(sha256(convert_to(coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text)::text,'[]'),'UTF8')),'hex') AS digest " +
     'FROM sites_preview.' + table + ' r' + where;
 }).join(' UNION ALL ') + ' ORDER BY table_name');
 const mainCounts = () => JSON.parse(sql("SELECT jsonb_build_object(" +
   "'versions',(SELECT count(*) FROM sites_preview.versions WHERE project_id='" + mainId + "'::uuid)," +
   "'reviews',(SELECT count(*) FROM sites_preview.reviews WHERE project_id='" + mainId + "'::uuid)," +
   "'audit',(SELECT count(*) FROM sites_preview.audit WHERE project_id='" + mainId + "'::uuid))::text"));
 const sorted = entries => JSON.stringify([...entries].sort(([left], [right]) => left.localeCompare(right)));
 const hasQuery = (url, expected) => sorted(new URL(url, base).searchParams) === sorted(Object.entries(expected));
 const urlFor = (id, query) => projectUrl(id) + '?' + new URLSearchParams(query);
 const extraNote = number => 'Parecer sintético ' + String(number).padStart(2, '0') +
   ' — café 漢😀 <b data-pagination-probe="literal">texto</b>\nLinha seguinte.';
 const context = await ctx('author', {javaScriptEnabled: false});
 context.setDefaultTimeout(20000);
 context.setDefaultNavigationTimeout(20000);
 const page = await context.newPage();
 const panel = () => page.getByTestId('version-reviews-panel');
 const items = () => panel().getByTestId('version-review-item');
 const pager = () => panel().getByTestId('version-reviews-pager');
 const notes = async () => items().evaluateAll(rows => rows.map(row => {
   const note = row.querySelector('[data-review-note]');
   return note === null ? null : note.textContent;
 }));
 const follow = async (targetPage, link) => {
   const target = new URL(await link.getAttribute('href'), base).toString();
   const [response] = await Promise.all([
     targetPage.waitForNavigation({waitUntil: 'load'}), link.click(),
   ]);
   await targetPage.waitForURL(target, {waitUntil: 'load'});
   if (response === null) throw Error('Expected a real review navigation response');
   return response;
 };
 const submit = async (targetPage, form) => {
   const action = new URL(await form.getAttribute('action'), base).toString();
   const [response, loaded] = await Promise.all([
     targetPage.waitForResponse(result => result.request().isNavigationRequest() &&
       result.request().method() === 'POST' && result.url() === action),
     targetPage.waitForNavigation({waitUntil: 'load'}), form.locator('button[type="submit"]').click(),
   ]);
   return {response, loaded, action, body: new URLSearchParams(response.request().postData())};
 };
 const noReadSelectors = result => ![...new URL(result.action).searchParams.keys(), ...result.body.keys()]
   .some(key => key.startsWith('review_') || key.startsWith('compare_'));
 let editor, other, completed = false, continuedUrl, firstSelectedUrl, lastCursor;
 try {
   const seedArgs = ['run', '--rm', '--no-deps', '--user', '0',
     '-v', process.env.PREVIEW_CI_MATERIAL + ':/run/ci:ro',
     '-v', path.join(process.env.GITHUB_WORKSPACE, 'tests/fixtures/preview-review-pagination') + ':/tmp/review-pagination:ro',
     '-e', 'GITHUB_ACTIONS=true', '-e', 'SITES_PREVIEW_HTTP_CI=1'];
   const seedCommand = ['app', 'php', '/tmp/review-pagination/http-seed.php', 'seed'];
   const beforeSeed = snapshot();
   let wrongClusterDenied = false;
   try { docker([...seedArgs, '-e', 'PREVIEW_EXPECTED_CLUSTER=1', ...seedCommand]); }
   catch (error) {
     wrongClusterDenied = error.status === 2 && String(error.stderr || '').includes('REVIEW_PAGINATION_HTTP_SEED_DENIED');
   }
   check(wrongClusterDenied && snapshot() === beforeSeed,
     'the late seed refuses the wrong cluster before changing any persisted row');
   const seeded = docker([...seedArgs, '-e', 'PREVIEW_EXPECTED_CLUSTER=' + process.env.PREVIEW_EXPECTED_CLUSTER, ...seedCommand]);
   if (!seeded.includes('REVIEW PAGINATION HTTP SEED PASS:')) throw Error('Review pagination seed did not finish');
   const initial = snapshot(), unrelated = snapshot(true), counts = mainCounts();
   check(counts.versions === 20 && counts.reviews === 135,
     'the synthetic fixture contains twenty versions and the bounded-summary counterexample');

   const queue = await page.goto(base + '/_preview?q=CI&status=awaiting_review&format=onepage');
   const queueNext = new URL(await page.getByRole('link', {name: 'Próximos projetos →', exact: true}).getAttribute('href'), base);
   const navigation = Object.fromEntries(queueNext.searchParams);
   check(queue.status() === 200 && navigation.q === 'CI' && navigation.status === 'awaiting_review' &&
     navigation.format === 'onepage' && typeof navigation.cursor === 'string' && navigation.cursor !== '',
     'navigation context uses an actual queue cursor and existing filters');
   const selection = {...navigation, before_revision: '21', compare_from: '1', compare_to: '20'};
   const initialPage = await page.goto(urlFor(mainId, selection));
   const history = page.getByTestId('editorial-review-history');
   check(initialPage.status() === 200 && await page.locator('[data-version]').count() === 20 &&
     await page.getByTestId('briefing-form').locator('[name="expected_revision"]').inputValue() === '20' &&
     (await history.textContent()).includes('Este resumo está limitado a 100 pareceres.') &&
     !(await history.textContent()).includes('Parecer API 02 da versão 1.') &&
     await page.locator('[data-version="1"]').getByTestId('version-reviews-link').count() === 1,
     'the original summary omits version-one feedback but its own card now offers a complete query');

   const first = await follow(page, page.locator('[data-version="1"]').getByTestId('version-reviews-link'));
   firstSelectedUrl = page.url();
   const html = await context.request.get(mainUrl + '/versions/1/html');
   const digest = crypto.createHash('sha256').update(await html.body()).digest('hex');
   const firstNotes = await notes();
   check(first.status() === 200 && html.status() === 200 && await items().count() === 20 &&
     await panel().getAttribute('data-review-revision') === '1' && await panel().getAttribute('data-review-digest') === digest &&
     await panel().getAttribute('aria-labelledby') === 'version-reviews-title' &&
     await panel().getByRole('heading', {name: 'Pareceres da versão 1', exact: true}).isVisible() &&
     hasQuery(page.url(), {...selection, review_revision: '1'}) && new URL(page.url()).hash === '#version-reviews',
     'the real GET opens twenty reviews for the exact immutable revision and keeps all navigation context');
   const originalNotes = [null, ...Array.from({length: 5}, (_, index) => 'Parecer API ' + String(index + 2).padStart(2, '0') + ' da versão 1.')];
   check(JSON.stringify([...firstNotes.slice(0, 6)].sort()) === JSON.stringify([...originalNotes].sort()) &&
     JSON.stringify(firstNotes.slice(6)) === JSON.stringify(Array.from({length: 14}, (_, index) => extraNote(index + 7))) &&
     await panel().getByTestId('version-review-no-note').count() === 1 &&
     await panel().getByText('Este parecer foi registrado sem observação.', {exact: true}).isVisible(),
     'the first page preserves null feedback and the exact ordered Unicode and multiline notes');
   check(await panel().locator('script,[data-pagination-probe],form,input,button').count() === 0 &&
     await panel().getByTestId('version-reviews-list').locator('a').count() === 0 &&
     !(await panel().textContent()).includes('sites_preview_rp511_') &&
     first.headers()['cache-control'].includes('no-store') &&
     await page.getByTestId('briefing-form').locator('[name="headline"]').inputValue() === 'Pareceres CI versão 20',
     'feedback remains inert and private while the editable briefing stays at its current head');
   continuedUrl = new URL(await pager().getByRole('link', {name: 'Pareceres anteriores →', exact: true}).getAttribute('href'), base).toString();
   const firstCursor = new URL(continuedUrl).searchParams.get('review_cursor');
   check(/^[a-f0-9]{64}$/.test(firstCursor) && hasQuery(continuedUrl, {...selection, review_revision: '1', review_cursor: firstCursor}) &&
     await pager().getByRole('link', {name: 'Voltar aos pareceres mais recentes', exact: true}).count() === 0,
     'continuation uses one opaque cursor and the first page offers no false previous page');
   for (const [name, viewport] of [['desktop', {width: 1440, height: 1000}], ['mobile', {width: 390, height: 844}]]) {
     await page.setViewportSize(viewport);
     await panel().scrollIntoViewIfNeeded();
     check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth) &&
       await panel().evaluate(node => node.scrollWidth <= node.clientWidth),
       name + ' keeps both the complete page and selected review panel within the viewport');
     await panel().screenshot({path: path.join(dir, 'review-pagination-' + name + '.png')});
   }
   await page.setViewportSize({width: 1440, height: 1000});
   const second = await follow(page, pager().getByRole('link', {name: 'Pareceres anteriores →', exact: true}));
   const secondNotes = await notes();
   check(second.status() === 200 && JSON.stringify(secondNotes) === JSON.stringify([extraNote(21)]) &&
     new Set([...firstNotes.filter(note => note !== null), ...secondNotes]).size === 20 &&
     await pager().getByRole('link', {name: 'Pareceres anteriores →', exact: true}).count() === 0,
     'the tied timestamp crosses the twenty-plus-one boundary without loss or overlap, including a revoked historical reviewer');

   const compareForm = page.getByTestId('comparison-form');
   check(await compareForm.locator('[name="review_revision"]').inputValue() === '1' &&
     await compareForm.locator('[name="review_cursor"]').inputValue() === firstCursor,
     'the real comparison GET form carries the selected review page as hidden read context');
   await compareForm.locator('[name="compare_from"]').fill('2');
   const [compared] = await Promise.all([
     page.waitForNavigation({waitUntil: 'load'}), compareForm.getByRole('button', {name: 'Comparar versões', exact: true}).click(),
   ]);
   check(compared !== null && compared.status() === 200 &&
     hasQuery(page.url(), {...selection, compare_from: '2', review_revision: '1', review_cursor: firstCursor}) &&
     JSON.stringify(await notes()) === JSON.stringify([extraNote(21)]),
     'submitting a comparison without JavaScript preserves the exact later review page');
   const closedComparison = await follow(page, page.getByRole('link', {name: 'Fechar comparação', exact: true}));
   const noComparison = {...navigation, before_revision: '21'};
   check(closedComparison.status() === 200 &&
     hasQuery(page.url(), {...noComparison, review_revision: '1', review_cursor: firstCursor}) &&
     await page.getByTestId('editorial-comparison').count() === 0 && await items().count() === 1,
     'closing the comparison keeps the selected review revision and its cursor');
   const restarted = await follow(page, pager().getByRole('link', {name: 'Voltar aos pareceres mais recentes', exact: true}));
   check(restarted.status() === 200 && hasQuery(page.url(), {...noComparison, review_revision: '1'}) && await items().count() === 20,
     'returning to the newest reviews resets only their cursor');
   await page.goto(continuedUrl);
   const closed = await follow(page, pager().getByRole('link', {name: 'Fechar consulta de pareceres', exact: true}));
   check(closed.status() === 200 && hasQuery(page.url(), selection) && new URL(page.url()).hash === '#version-history' &&
     await panel().count() === 0 && await page.getByTestId('editorial-comparison').isVisible(),
     'closing review inspection returns to the history and keeps the selected comparison and queue context');
   check(snapshot() === initial, 'opening, paginating, comparing and closing reviews change none of the five persisted tables');

   editor = await ctx('editor', {javaScriptEnabled: false});
   editor.setDefaultTimeout(20000); editor.setDefaultNavigationTimeout(20000);
   const editorPage = await editor.newPage();
   await editorPage.goto(firstSelectedUrl);
   const reviewForm = editorPage.locator('[data-version="1"] form.review');
   await reviewForm.locator('[name="decision"]').selectOption('approved_internal');
   const newNote = 'Parecer HTTP registrado após abrir a paginação.';
   await reviewForm.locator('[name="note"]').fill(newNote);
   const reviewed = await submit(editorPage, reviewForm);
   check(reviewed.response.status() === 302 && reviewed.loaded !== null && reviewed.loaded.status() === 200 &&
     noReadSelectors(reviewed) && reviewed.body.get('sha256') === digest &&
     editorPage.url().includes('/_preview/projects/' + mainId) &&
     !new URL(editorPage.url()).searchParams.has('review_revision') && !new URL(editorPage.url()).searchParams.has('compare_from'),
     'a real independent review POST uses its own CSRF and digest without read selectors or automatic replay');
   await page.goto(firstSelectedUrl);
   const briefForm = page.getByTestId('briefing-form');
   const expectedRevision = await briefForm.locator('[name="expected_revision"]').inputValue();
   await briefForm.locator('[name="headline"]').fill('Pareceres CI versão 21');
   const appended = await submit(page, briefForm);
   const changedCounts = mainCounts();
   check(expectedRevision === '20' && appended.response.status() === 302 && appended.loaded !== null && appended.loaded.status() === 200 &&
     noReadSelectors(appended) && await page.getByTestId('briefing-form').locator('[name="expected_revision"]').inputValue() === '21' &&
     changedCounts.versions === counts.versions + 1 && changedCounts.reviews === counts.reviews + 1 &&
     changedCounts.audit === counts.audit + 2 && snapshot(true) === unrelated,
     'one real append advances only the head while the two explicit POSTs add exactly one version, one review and two audit rows');

   const afterMutations = snapshot();
   const resumed = await page.goto(continuedUrl);
   check(resumed.status() === 200 && JSON.stringify(await notes()) === JSON.stringify([extraNote(21)]) &&
     await panel().getAttribute('data-review-revision') === '1' && await panel().getAttribute('data-review-digest') === digest &&
     await page.getByTestId('briefing-form').locator('[name="expected_revision"]').inputValue() === '21' &&
     await page.getByTestId('briefing-form').locator('[name="headline"]').inputValue() === 'Pareceres CI versão 21' &&
     await page.locator('[data-version="21"]').count() === 0 && !(await panel().textContent()).includes(newNote),
     'the old opaque continuation survives a new review and head without changing its revision, duplicating rows or replacing the current briefing');
   const refreshed = await follow(page, pager().getByRole('link', {name: 'Voltar aos pareceres mais recentes', exact: true}));
   const refreshedNotes = await notes();
   const refreshedQuery = page.url();
   const refreshedNext = await follow(page, pager().getByRole('link', {name: 'Pareceres anteriores →', exact: true}));
   check(refreshed.status() === 200 && refreshedNext.status() === 200 && refreshedNotes.length === 20 &&
     refreshedNotes[0] === newNote && refreshedNotes.at(-1) === extraNote(19) &&
     hasQuery(refreshedQuery, {...selection, review_revision: '1'}) &&
     JSON.stringify(await notes()) === JSON.stringify([extraNote(20), extraNote(21)]) &&
     await pager().getByRole('link', {name: 'Pareceres anteriores →', exact: true}).count() === 0,
     'a native restart finds the new review first and walks the updated twenty-plus-two pages completely');
   const currentOpened = await follow(page, page.getByTestId('current-editorial-guidance').getByTestId('version-reviews-link'));
   check(currentOpened.status() === 200 && hasQuery(page.url(), {...selection, review_revision: '21'}) &&
     await panel().getAttribute('data-review-revision') === '21' &&
     (await panel().getByTestId('version-reviews-empty').textContent()).trim() === 'Esta versão ainda não recebeu parecer editorial.',
     'the current guidance opens its own first review page even when that version is outside the history window');
   await page.goto(continuedUrl);
   const newer = await follow(page, page.getByRole('link', {name: '← Versões mais recentes', exact: true}));
   const newerSelection = {...navigation, after_revision: '20', compare_from: '1', compare_to: '20',
     review_revision: '1', review_cursor: firstCursor};
   check(newer.status() === 200 && hasQuery(page.url(), newerSelection) &&
     await page.locator('[data-version]').count() === 1 && await page.locator('[data-version="21"]').count() === 1 &&
     JSON.stringify(await notes()) === JSON.stringify([extraNote(21)]),
     'history pagination preserves the independently selected review page and comparison');

   // The only cursor constructed by the fixture addresses the already known
   // final row, proving the valid empty continuation separately from absence.
   lastCursor = sql("SELECT encode(sha256(convert_to(jsonb_build_array('SITES_PREVIEW_REVIEW_CURSOR_V1'," +
     "v.company_id::text,v.project_id::text,v.revision,v.sha256," +
     "to_char(r.created_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'),r.reviewer::text)::text,'UTF8')),'hex') " +
     'FROM sites_preview.versions v JOIN sites_preview.reviews r ON r.project_id=v.project_id AND r.company_id=v.company_id ' +
     'AND r.revision=v.revision AND r.sha256=v.sha256 ' +
     "WHERE v.project_id='" + mainId + "'::uuid AND v.revision=1 AND r.reviewer='sites_preview_rp511_a_21'");
   if (!/^[a-f0-9]{64}$/.test(lastCursor)) throw Error('Final synthetic review cursor unavailable');
   const emptyContinuation = await page.goto(urlFor(mainId, {...selection, review_revision: '1', review_cursor: lastCursor}));
   check(emptyContinuation.status() === 200 && await items().count() === 0 &&
     await panel().getByTestId('version-reviews-empty').textContent() === 'Nenhum parecer nesta página. Volte aos pareceres mais recentes.' &&
     await pager().getByRole('link', {name: 'Voltar aos pareceres mais recentes', exact: true}).isVisible() &&
     await pager().getByRole('link', {name: 'Pareceres anteriores →', exact: true}).count() === 0,
     'a cursor anchored at the final real row produces an honest empty later page with a return path');
   const emptyVersion = await page.goto(urlFor(emptyId, {review_revision: '1'}));
   check(emptyVersion.status() === 200 && await items().count() === 0 &&
     (await panel().getByTestId('version-reviews-empty').textContent()).trim() === 'Esta versão ainda não recebeu parecer editorial.' &&
     await pager().getByRole('link', {name: 'Voltar aos pareceres mais recentes', exact: true}).count() === 0,
     'a known authorized version with no reviews has a distinct initial empty state');

   const malformed = [
     mainUrl + '?review_revision[]=1', mainUrl + '?review_revision=01',
     mainUrl + '?review_revision=1&review_cursor[]=x', mainUrl + '?review_cursor=' + firstCursor,
     mainUrl + '?review_revision=1&review_cursor=' + 'A'.repeat(64),
     mainUrl + '?review_revision=%201',
     mainUrl + '?review_revision=1&review_cursor=%20' + firstCursor,
     mainUrl + '?review_revision=1&review_cursor=' + firstCursor + '%00',
     base + '/_preview?review_revision=1',
   ];
   for (const target of malformed) {
     const response = await context.request.get(target), text = await response.text();
     check(response.status() === 422 && !text.includes('version-reviews-panel') && !text.includes('Parecer API'),
       'malformed or misplaced read selectors are refused without a review panel');
   }
   const versionTwo = await context.request.get(urlFor(mainId, {review_revision: '2'}));
   const wrongRevision = await context.request.get(urlFor(mainId, {review_revision: '2', review_cursor: firstCursor}));
   const wrongProject = await context.request.get(urlFor(emptyId, {review_revision: '1', review_cursor: firstCursor}));
   const missingVersion = await context.request.get(urlFor(mainId, {review_revision: '9999'}));
   const unknownCursor = await context.request.get(urlFor(mainId, {review_revision: '1', review_cursor: '0'.repeat(64)}));
   check(versionTwo.status() === 200 && [wrongRevision, wrongProject, missingVersion, unknownCursor].every(response => response.status() === 404) &&
     !(await wrongRevision.text()).includes('version-reviews-panel') && !(await wrongProject.text()).includes('version-reviews-panel'),
     'valid but absent or differently scoped anchors cannot select another project or revision');
   other = await ctx('other', {javaScriptEnabled: false});
   const ownForeign = await other.request.get(urlFor(foreignId, {review_revision: '1'}));
   const crossCompany = await other.request.get(continuedUrl);
   const authorToForeign = await context.request.get(urlFor(foreignId, {review_revision: '1'}));
   check(ownForeign.status() === 200 && (await ownForeign.text()).includes('version-reviews-panel') &&
     [403, 404].includes(crossCompany.status()) && [403, 404].includes(authorToForeign.status()) &&
     !(await crossCompany.text()).includes('Parecer sintético') && !(await authorToForeign.text()).includes('version-reviews-panel'),
     'each tenant can read its own known version while the same selection and cursor grant no cross-company access');
   check(snapshot() === afterMutations,
     'all resumed, empty and refused reads leave the five persisted tables exactly as the two explicit POSTs left them');
   completed = true;
 } finally {
   await page.close();
   if (editor) await editor.close();
   if (other) await other.close();
   if (!completed) await context.close();
 }
 return {verifyRevocation: async () => {
   try {
     const before = snapshot();
     const responses = await Promise.all([context.request.get(firstSelectedUrl), context.request.get(continuedUrl)]);
     const bodies = await Promise.all(responses.map(response => response.text()));
     check(responses.every(response => response.status() === 403) &&
       bodies.every(text => !text.includes('version-reviews-panel') && !text.includes('Parecer sintético')),
       'revocation refuses the same first page and continuation that previously succeeded');
     check(snapshot() === before, 'refused review reads preserve the revoked persisted state');
     console.log('REVIEW PAGINATION HTTP PASS: ' + newChecks + ' new checks; ' + baselineChecks + ' existing checks retained.');
   } finally { await context.close(); }
 }};
};
