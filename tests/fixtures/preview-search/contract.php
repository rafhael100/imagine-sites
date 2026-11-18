<?php
declare(strict_types=1);

use Imagine\Sites\Production\{PreviewQueueQuery,SiteBrief};

// Run only after the parent has authenticated its own fresh, disposable PG18
// cluster and completed the existing workspace/global-count assertions.
if (!isset($admin,$connections,$workspaces,$overviews,$companies,$ok,$count,$overviewDenied,$historyDigest) ||
    getenv('SITES_LOCAL_ROLE_CONTRACT') !== '1') {
    throw new RuntimeException('Queue search requires the verified role fixture');
}
$searchChecksBefore=$count;
$ok($admin->query('SHOW server_encoding')->fetchColumn()==='UTF8' &&
    $admin->query('SELECT datctype FROM pg_catalog.pg_database WHERE datname=current_database()')->fetchColumn()==='C',
    'Unicode queue search is exercised in a real UTF8 database with C locale');

$searchNextId=1;
$searchCreate=static function(string $brand,string $headline='Chamada sintética de busca',
    string $product='onepage',string $tenant='a',?string $description=null)use(
    &$searchNextId,$workspaces,$companies
):array {
    $id='90000000-0000-4000-8000-'.str_pad((string)$searchNextId++,12,'0',STR_PAD_LEFT);
    $brief=new SiteBrief($product,$brand,$headline,$description??'Conteúdo público exclusivamente fictício.',
        'Conheça',['Benefício sintético']);
    return ['id'=>$id,'brief'=>$brief,'version'=>$workspaces[$tenant]['author']->createDemonstration(
        $id,$companies[$tenant],$brief
    )];
};
$searchPage=static fn(string $term,string $status='all',string $product='all',?string $cursor=null,
    string $tenant='a'):array=>$overviews[$tenant]['reader']->page($companies[$tenant],
        new PreviewQueueQuery($status,$product,$cursor,$term));
$searchIds=static fn(array $page):array=>array_column($page['items'],'id');
$searchSameIds=static function(array $page,array $expected)use($searchIds):bool {
    $actual=$searchIds($page);sort($actual);sort($expected);return $actual===$expected;
};

$accent=$searchCreate('AÇÃO DE BUSCA','Título sintético alfa');
$headline=$searchCreate('Marca sem o termo procurado','CHAMADA ÚNICA DE BUSCA');
$german=$searchCreate('Straße fictícia');
$greek=$searchCreate('Busca ΟΣ');
$cafe=$searchCreate('Café de busca');
$nfd=$searchCreate("Marca NFD Cafe\u{0301}");
$literal=$searchCreate('Literal % _ \\ [a-z].* de busca');
$quoted=$searchCreate("Marca fictícia ' OR 1=1 --");
$outside=$searchCreate('EscopoMarca493','EscopoChamada493','onepage','a','BuscaSomenteDescrição493');
$boundary=$searchCreate('Limite '.str_repeat('😀',100));
$oldHead=$searchCreate('Marca Antiga Busca493','Chamada Antiga Busca493');

$ok($searchIds($searchPage('ação de busca'))===[$accent['id']],
    'non-ASCII uppercase brand matches a lowercase literal term under C locale');
$ok($searchIds($searchPage('chamada única de busca'))===[$headline['id']],
    'the current headline is independently searchable with Unicode case folding');
$ok($searchIds($searchPage('STRASSE FICTÍCIA'))===[$german['id']],
    'Unicode case folding supports the sharp-s expansion rather than ASCII-only lowercasing');
$ok($searchIds($searchPage('busca ος'))===[$greek['id']] &&
    $searchIds($searchPage('busca οσ'))===[$greek['id']],
    'ordinary and final Greek sigma find the same current brand');
$ok($searchIds($searchPage('CAFÉ DE BUSCA'))===[$cafe['id']] &&
    $searchIds($searchPage('cafe de busca'))===[],
    'case folding preserves accents instead of silently enabling accent-insensitive matching');
$ok($searchIds($searchPage("cafe\u{0301} de busca"))===[] &&
    $searchIds($searchPage('marca nfd CAFÉ'))===[] &&
    $searchIds($searchPage("MARCA NFD CAFE\u{0301}"))===[$nfd['id']],
    'canonically distinct Unicode sequences remain literal and are not normalized by search');
foreach (['%','_','\\','[a-z].*'] as $term) {
    $ok($searchIds($searchPage($term))===[$literal['id']],
        'search treats the pattern-like term as literal text: '.json_encode($term,JSON_THROW_ON_ERROR));
}
$ok($searchIds($searchPage("' OR 1=1 --"))===[$quoted['id']] &&
    $searchIds($searchPage("'; SELECT true; --"))===[],
    'quotes and SQL-looking input remain bound literal text without broadening results');
$ok($searchIds($searchPage('BuscaSomenteDescrição493'))===[],
    'description text is outside the declared brand/headline search scope');
$ok($searchIds($searchPage(str_repeat('😀',100)))===[$boundary['id']],
    'a full 100-codepoint 400-byte Unicode term reaches PostgreSQL intact');
$ok($searchPage("\u{00A0}ação de busca\u{3000}")===$searchPage('ação de busca'),
    'Unicode whitespace at the term edges is normalized before the SQL query');
$ok($searchIds($searchPage('Marca Antiga Busca493'))===[$oldHead['id']] &&
    $searchIds($searchPage('Chamada Antiga Busca493'))===[$oldHead['id']],
    'the original brand and headline are searchable before a revision is added');
$workspaces['a']['reviewer']->review($oldHead['id'],$companies['a'],1,
    $oldHead['version']['sha256'],'approved_internal');
$newHead=$workspaces['a']['author']->revise($oldHead['id'],$companies['a'],1,
    new SiteBrief('onepage','Marca Nova Busca493','Chamada Atual Busca493',
        'Uma revisão nova exclusivamente fictícia.','Conheça',['Exemplo atual']));
$headPage=$searchPage('Marca Nova Busca493');
$ok($searchIds($searchPage('Marca Antiga Busca493'))===[] &&
    $searchIds($searchPage('Chamada Antiga Busca493'))===[] &&
    $searchIds($headPage)===[$oldHead['id']] &&
    $headPage['items'][0]['latest_revision']===2 &&
    $headPage['items'][0]['sha256']===$newHead['sha256'] &&
    $headPage['items'][0]['editorial_status']==='awaiting_review',
    'search considers only the latest revision and does not inherit old text or approval');

$filtered=[];
foreach ([['onepage','awaiting_review'],['landing_page','approved_internal'],
    ['onepage','changes_requested'],['onepage','approved_internal']] as [$product,$status]) {
    $entry=$searchCreate('FiltroBusca493','Chamada do filtro '.$status,$product);
    $filtered[$product.'/'.$status]=$entry['id'];
    if ($status!=='awaiting_review') $workspaces['a']['reviewer']->review(
        $entry['id'],$companies['a'],1,$entry['version']['sha256'],$status,
        $status==='changes_requested'?'Ajuste sintético para a fixture do filtro.':null
    );
}
$ok($searchSameIds($searchPage('filtrobusca493'),array_values($filtered)),
    'one literal term can return both products and all current editorial states');
$ok($searchIds($searchPage('filtrobusca493','changes_requested','onepage'))===
    [$filtered['onepage/changes_requested']],
    'search, editorial status and product are combined with AND');
$ok($searchSameIds($searchPage('filtrobusca493','approved_internal'),
    [$filtered['onepage/approved_internal'],$filtered['landing_page/approved_internal']]) &&
    $searchIds($searchPage('filtrobusca493','approved_internal','landing_page'))===
    [$filtered['landing_page/approved_internal']],
    'approval filtering stays scoped to the selected product and search results');
$ok($searchIds($searchPage('filtrobusca493','awaiting_review','landing_page'))===[] &&
    $searchIds($searchPage('termosemcorrespondência493','changes_requested','onepage'))===[],
    'a failed filter cannot be bypassed by a matching status or the alternate text field');

// Equal timestamps force the UUID tie-break branch of the keyset cursor. The
// existing author INSERT capability appends fixture versions; no history is
// updated and no owner/grant/constraint is changed to prepare the fixture.
$paginationExpected=[];$paginationDistractors=[];
$paginationAppend=$connections['a']['author']->prepare(<<<'SQL'
INSERT INTO sites_preview.versions(project_id,company_id,revision,brief,html,sha256,created_at)
SELECT project_id,company_id,2,brief,html,sha256,'2026-01-01T12:00:00.000000Z'::timestamptz
  FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid AND revision=1
SQL);
for ($index=0;$index<60;$index++) {
    $matches=$index%10!==0;
    $entry=$searchCreate($matches?'PaginacaoBusca493':'Intercalado sem correspondência493',
        'Projeto sintético '.str_pad((string)$index,2,'0',STR_PAD_LEFT));
    $paginationAppend->execute([$entry['id'],$companies['a']]);
    if ($matches) $paginationExpected[]=$entry['id'];
    else $paginationDistractors[]=$entry['id'];
}
$paginationFirst=$searchPage('paginacaobusca493','awaiting_review','onepage');
$ok(count($paginationExpected)===54 && count($paginationDistractors)===6 &&
    $searchIds($paginationFirst)===array_slice($paginationExpected,0,50) &&
    $paginationFirst['has_more']===true && is_string($paginationFirst['next_cursor']),
    'search filters before the 50-item limit despite interleaved nonmatching projects');
$paginationSecond=$searchPage('paginacaobusca493','awaiting_review','onepage',
    $paginationFirst['next_cursor']);
$ok($searchIds($paginationSecond)===array_slice($paginationExpected,50) &&
    $paginationSecond['has_more']===false && $paginationSecond['next_cursor']===null &&
    array_merge($searchIds($paginationFirst),$searchIds($paginationSecond))===$paginationExpected,
    'the equal-timestamp cursor returns all 54 matches once in UUID order across both pages');
$ok($searchPage('paginacaobusca493','awaiting_review','onepage')===$paginationFirst,
    'replaying the same read navigation is stable and creates no new cursor authority');

$other=$searchCreate('Busca Reservada Empresa B493','Chamada reservada fictícia','onepage','b');
$ok($searchIds($searchPage('busca reservada empresa b493',tenant:'b'))===[$other['id']] &&
    $searchIds($searchPage('busca reservada empresa b493'))===[],
    'a real matching project in the other company stays invisible to the authenticated reader');
$overviewDenied(static fn()=>$overviews['a']['reader']->page($companies['b'],
    new PreviewQueueQuery(search:'busca reservada empresa b493')),
    'a valid search and known match do not authorize another company scope');
$overviewDenied(static fn()=>$overviews['b']['reader']->page($companies['a'],
    new PreviewQueueQuery(search:'ação de busca')),
    'another authenticated login cannot search the original company by supplying its id');

$searchState=static fn():array=>[$historyDigest(),$admin->query(
    "SELECT md5(string_agg(row_to_json(p)::text,'' ORDER BY id)) FROM sites_preview.projects p"
)->fetchColumn()];
$searchBefore=$searchState();
$emptySearch=$overviews['a']['reader']->page($companies['a']);
$ok($searchPage('')===$emptySearch && $searchPage(" \u{00A0}\u{2003}\u{3000}")===$emptySearch,
    'empty and Unicode-white terms preserve the existing unfiltered queue exactly');
$positive=$searchPage('ação de busca');
$ok($searchIds($positive)===[$accent['id']],
    'the reused reader has a known valid search result before revocation');
$admin->exec("UPDATE sites_preview.principals SET revoked_at=clock_timestamp() WHERE database_login='lab486_a_reader'");
try {
    $overviewDenied(static fn()=>$searchPage('ação de busca'),
        'the already-connected reader cannot reuse a valid search after principal revocation');
} finally {
    $admin->exec("UPDATE sites_preview.principals SET revoked_at=NULL WHERE database_login='lab486_a_reader'");
}
$ok($searchPage('ação de busca')===$positive,
    'restoring only the synthetic principal binding restores the same immutable search result');
$searchReadOnly=$connections['a']['reader']->query('SHOW default_transaction_read_only')->fetchColumn();
$connections['a']['reader']->exec('SET default_transaction_read_only=on');
try {
    $ok($connections['a']['reader']->query('SHOW transaction_read_only')->fetchColumn()==='on' &&
        $searchPage('ação de busca')===$positive &&
        $searchPage('paginacaobusca493','awaiting_review','onepage',$paginationFirst['next_cursor'])===$paginationSecond,
        'Unicode search and keyset paging work through an actual read-only PostgreSQL session');
} finally {
    $connections['a']['reader']->exec($searchReadOnly==='on'
        ?'SET default_transaction_read_only=on':'SET default_transaction_read_only=off');
}
$ok($searchState()===$searchBefore,
    'successful, empty, paged and revoked searches leave projects, versions, reviews and audit unchanged');
echo 'QUEUE SEARCH SQL PASS: '.($count-$searchChecksBefore).
    " assertions; PG18 C locale Unicode folding, literal terms, current-head scope, combined filters, keyset paging, tenant isolation, revocation and readonly behavior\n";
