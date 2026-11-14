<?php

declare(strict_types=1);

/** F1-382: real disposable PG18 review workflow across independent editor
 * and reviewer SQL logins, using the F1-381 preview manifest as immutable
 * content-digest evidence. No client identity, publishing or real approval. */
if (PHP_SAPI!=='cli' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F382_REVIEW_LAB')!=='1' ||
    getenv('DB_HOST')!=='sites-db') {
    fwrite(STDERR,"F1-382 only runs in the disposable GitHub CI network.\n");
    exit(1);
}
$manifest=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
if (!is_array($manifest) ||
    ($manifest['schemaVersion']??null)!=='sites.ci.review-bundle.v1' ||
    ($manifest['releaseGate']??null)!=='NO_GO' ||
    ($manifest['clientApproved']??null)!==false ||
    ($manifest['authorizesPublishing']??null)!==false ||
    count($manifest['items']??[])!==2) {
    throw new RuntimeException('Missing nonpublishable F1-381 source.');
}
$digests=[];
foreach ($manifest['items'] as $record) {
    $sku=$record['product']??null;
    $hash=$record['sha256']??null;
    if (!in_array($sku,['onepage','landing_page'],true) ||
        !is_string($hash) ||
        !preg_match('/\A[a-f0-9]{64}\z/D',$hash) ||
        isset($digests[$sku])) {
        throw new RuntimeException('Unsafe preview manifest record.');
    }
    $digests[$sku]=$hash;
}
if (count($digests)!==2) throw new RuntimeException('Missing two SKU digests.');
$dsn='pgsql:host=sites-db;port=5432;dbname=sites_f360_lab';
$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_EMULATE_PREPARES=>false,
      PDO::ATTR_TIMEOUT=>6];
$auditor=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$opt);
$editor=new PDO($dsn,'sites_f382_editor','f382-fictional-editor-only',$opt);
$reviewer=new PDO($dsn,'sites_f382_reviewer','f382-fictional-reviewer-only',$opt);
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$opt);

function f382Check(bool $condition,string $name):void {
    global $checks;
    if (!$condition) throw new RuntimeException('F1-382 FAIL: '.$name);
    ++$checks;
}
function f382Value(PDO $db,string $function,array $params):mixed {
    $args=implode(',',array_fill(0,count($params),'?'));
    $statement=$db->prepare('SELECT sites_f382.'.$function.'('.$args.')');
    $statement->execute($params);
    return $statement->fetchColumn();
}
function f382Denied(callable $work,?string $code=null):bool {
    try{$work();}
    catch(PDOException $e) {
        return $code===null || $e->getCode()===$code;
    }
    return false;
}
$checks=0;
$sites=$auditor->query(
    'SELECT * FROM sites_f360.list_own_review_candidates()'
)->fetchAll(PDO::FETCH_ASSOC);
f382Check(count($sites)===2,'two admitted company-B Sites source rows');
f382Check($editor->query('SELECT session_user')->fetchColumn()==='sites_f382_editor' &&
    $reviewer->query('SELECT session_user')->fetchColumn()==='sites_f382_reviewer',
    'separate authenticated editor and reviewer SQL sessions');
f382Check(f382Denied(static fn()=> $editor->query('SELECT * FROM sites_f382.candidates'),'42501'),
    'editor direct table SELECT forbidden');
f382Check(f382Denied(static fn()=> $reviewer->query('UPDATE sites_f382.candidates SET publishable=true'),'42501'),
    'reviewer cannot write publishable flag or bypass SQL API');
f382Check(f382Denied(static fn()=> $editor->exec('SET ROLE sites_f382_reviewer'),'42501'),
    'editor login cannot impersonate reviewer');
f382Check(f382Denied(static fn()=> f382Value(
    $reviewer,'register_draft',['site-forgery',str_repeat('a',64)]
),'42501'),'reviewer lacks draft-creation execute permission');
f382Check(f382Denied(static fn()=> f382Value(
    $editor,'approve_internal',['site-forgery',1,str_repeat('a',64)]
),'42501'),'editor lacks internal-approval execute permission');

$summary=[];
foreach ($sites as $site) {
    $product=$site['product_code'];
    $siteRef=$site['site_ref'];
    $digest=$digests[$product]??null;
    if (!is_string($digest)) throw new RuntimeException('Missing product digest.');
    f382Check((int)f382Value($editor,'register_draft',[$siteRef,$digest])===1,
        'editor registered one admitted fictional '.$product);
    f382Check(f382Denied(static fn()=>f382Value(
        $editor,'register_draft',[$siteRef,$digest]
    ),'23505'),'duplicate draft registration rejected by DB');
    f382Check(f382Denied(static fn()=>f382Value(
        $editor,'submit_review',[$siteRef,0,$digest]
    ),'40001'),'stale revision cannot submit');
    f382Check((int)f382Value(
        $editor,'submit_review',[$siteRef,1,$digest]
    )===2,'editor submitted immutable digest to internal review');
    f382Check(f382Denied(static fn()=>f382Value(
        $reviewer,'approve_internal',[$siteRef,2,str_repeat('b',64)]
    ),'40001'),'reviewer cannot approve a different HTML digest');

    if ($product==='landing_page') {
        f382Check((int)f382Value(
            $reviewer,'request_rework',[$siteRef,2]
        )===3,'reviewer returned landing page for editorial revision');
        $view=$reviewer->prepare('SELECT * FROM sites_f382.read_review(?)');
        $view->execute([$siteRef]);
        $before=$view->fetch(PDO::FETCH_ASSOC);
        f382Check(is_array($before) && $before['review_state']==='draft' &&
            $before['digest']===null,
            'rework invalidated prior content digest');
        $revised=hash('sha256','fictional landing revision two / not a release');
        f382Check((int)f382Value(
            $editor,'replace_draft',[$siteRef,3,$revised]
        )===4,'editor replaced landing draft after rework');
        f382Check(f382Denied(static fn()=>f382Value(
            $editor,'submit_review',[$siteRef,3,$revised]
        ),'40001'),'stale editor revision cannot submit');
        f382Check((int)f382Value(
            $editor,'submit_review',[$siteRef,4,$revised]
        )===5,'review submitted revised landing digest');
        f382Check((int)f382Value(
            $reviewer,'approve_internal',[$siteRef,5,$revised]
        )===6,'independent reviewer approved revised digest for Portal');
        $expectedRevision=6;
        $expectedDigest=$revised;
    } else {
        f382Check((int)f382Value(
            $reviewer,'approve_internal',[$siteRef,2,$digest]
        )===3,'independent reviewer approved Onepage for Portal');
        $expectedRevision=3;
        $expectedDigest=$digest;
    }
    $read=$reviewer->prepare('SELECT * FROM sites_f382.read_review(?)');
    $read->execute([$siteRef]);
    $actual=$read->fetch(PDO::FETCH_ASSOC);
    f382Check(is_array($actual) &&
        $actual['product_code']===$product &&
        $actual['review_state']==='awaiting_portal_acceptance' &&
        (int)$actual['review_revision']===$expectedRevision &&
        $actual['digest']===$expectedDigest &&
        in_array($actual['client_accepted'],[false,'f','0',0],true) &&
        in_array($actual['publishable'],[false,'f','0',0],true),
        'review is durable but client acceptance and publication are both false');
    f382Check(f382Denied(static fn()=>f382Value(
        $reviewer,'approve_internal',[$siteRef,2,$digest]
    ),'40001'),'old reviewer approval cannot replay');
    f382Check(f382Denied(static fn()=>f382Value(
        $editor,'replace_draft',[$siteRef,$expectedRevision,$digest]
    ),'40001'),'editor cannot overwrite the awaiting-client review');
    $summary[]=[
        'product'=>$product,
        'revision'=>$expectedRevision,
        'state'=>'awaiting_portal_acceptance',
        'publishable'=>false,
        'clientAccepted'=>false,
    ];
}
$query=$admin->query(
    "SELECT count(*) AS total,
            count(*) FILTER (WHERE publishable=true OR client_accepted=true) AS invalid,
            count(*) FILTER (WHERE state='awaiting_portal_acceptance') AS awaiting
     FROM sites_f382.candidates"
)->fetch(PDO::FETCH_ASSOC);
f382Check((int)$query['total']===2 && (int)$query['invalid']===0 &&
    (int)$query['awaiting']===2,'two durable, nonpublishable reviews remain');
$events=$admin->query('SELECT count(*) FROM sites_f382.events')->fetchColumn();
f382Check((int)$events===9,'append-only audit recorded exact reviewed revisions');

$admin->exec(
    "UPDATE sites_f382.operator_bindings SET active=false WHERE login_name='sites_f382_editor'"
);
try {
    f382Check(f382Denied(static fn()=>f382Value(
        $editor,'register_draft',['site-f382-forgery',str_repeat('a',64)]
    ),'42501'),'revoked SQL editor cannot create another draft');
} finally {
    $admin->exec(
        "UPDATE sites_f382.operator_bindings SET active=true WHERE login_name='sites_f382_editor'"
    );
}
f382Check(f382Denied(static fn()=>f382Value(
    $editor,'register_draft',['site-from-another-tenant',str_repeat('a',64)]
),'42501'),'editor cannot synthesize a project outside authorized Sites');

echo json_encode([
    'schemaVersion'=>'sites.ci.editorial-review-evidence.v1',
    'fictionalOnly'=>true,
    'auditEvents'=>9,
    'clientAcceptanceVerified'=>false,
    'canPublish'=>false,
    'releaseGate'=>'NO_GO',
    'checksPassed'=>$checks,
    'reviews'=>$summary,
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
