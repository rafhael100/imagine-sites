<?php
declare(strict_types=1);

use App\Preview\PreviewBriefInput;
use Illuminate\Validation\ValidationException;
use Imagine\Sites\Production\{PrivatePreviewComparison,PrivatePreviewHistory,SiteBrief};

if (!isset($admin,$connections,$workspaces,$companies,$ok,$count,$historyDigest) ||
    getenv('SITES_LOCAL_ROLE_CONTRACT')!=='1') {
    throw new RuntimeException('Unicode briefing requires the verified role fixture');
}
$briefChecksBefore=$count;
$briefProjects=['a'=>'91000000-0000-4000-8000-000000000001','b'=>'91000000-0000-4000-8000-000000000002'];
// Every text reaches its actual codepoint limit, including eight 180-character
// benefits. Four-byte characters exercise the PHP -> JSON -> PG18 -> HTML path.
$briefBenefits=array_map(static fn(string $symbol):string=>str_repeat($symbol,180),
    ['😀','🚀','🌟','🧪','🍀','🎯','💡','🪴']);
$briefBoundary=['brand'=>str_repeat('😀',120),'headline'=>str_repeat('🚀',180),
    'description'=>str_repeat('🌟',600),'call_to_action'=>str_repeat('🎯',80),
    'benefits'=>implode("\r\n",$briefBenefits)];
$briefExpected=[];$briefCreated=[];$briefHtml=[];
$briefCanonical=static function(array $value):array {ksort($value);return $value;};
$briefRead=$admin->prepare('SELECT brief::text,html,sha256 FROM sites_preview.versions
    WHERE project_id=?::uuid AND company_id=?::uuid AND revision=?');
foreach (['a'=>'onepage','b'=>'landing_page'] as $tenant=>$product) {
    $input=['product'=>$product]+$briefBoundary;
    $brief=PreviewBriefInput::parse($input);
    $briefExpected[$tenant]=get_object_vars($brief);
    $briefCreated[$tenant]=$workspaces[$tenant]['author']->createDemonstration(
        $briefProjects[$tenant],$companies[$tenant],$brief);
    $briefRead->execute([$briefProjects[$tenant],$companies[$tenant],1]);
    $stored=$briefRead->fetch(PDO::FETCH_ASSOC);$briefHtml[$tenant]=$stored['html'];
    $decoded=json_decode($stored['brief'],true,12,JSON_THROW_ON_ERROR);
    $ok($briefCanonical($decoded)===$briefCanonical($briefExpected[$tenant]) &&
        $decoded['benefits']===$briefBenefits && mb_strlen($decoded['brand'],'UTF-8')===120 &&
        mb_strlen($decoded['headline'],'UTF-8')===180 && mb_strlen($decoded['description'],'UTF-8')===600 &&
        mb_strlen($decoded['callToAction'],'UTF-8')===80 &&
        $stored['sha256']===$briefCreated[$tenant]['sha256'] && hash('sha256',$stored['html'])===$stored['sha256'] &&
        str_contains($stored['html'],$briefBoundary['headline']) &&
        str_contains($stored['html'],'<body data-site-product="'.($product==='onepage'?'onepage':'landing-page').'">'),
        'full Unicode boundary survives JSONB and persisted artifact digest: '.$product);
    $history=(new PrivatePreviewHistory($connections[$tenant]['reader']))->page($companies[$tenant],$briefProjects[$tenant]);
    $read=$workspaces[$tenant]['reader']->view($briefProjects[$tenant],$companies[$tenant],1);
    $ok($history['brief']===$briefExpected[$tenant] && $history['current_revision']===1 &&
        $history['current_sha256']===$stored['sha256'] && $read['html']===$stored['html'] &&
        $history['current_reviews']===[],
        'restricted reader recovers the entire maximum briefing without inherited review: '.$product);
}

$briefRevisedDescription="Ação, café e cafe\u{0301}. <strong>Literal</strong> 😀\r\nSegunda linha.";
$briefRevisedInput=['product'=>'onepage','expected_revision'=>'1']+$briefBoundary;
$briefRevisedInput['description']=$briefRevisedDescription;
$briefRevised=PreviewBriefInput::parse($briefRevisedInput,'onepage');
$briefSecond=$workspaces['a']['author']->revise($briefProjects['a'],$companies['a'],1,$briefRevised);
$briefRead->execute([$briefProjects['a'],$companies['a'],2]);$briefStoredSecond=$briefRead->fetch(PDO::FETCH_ASSOC);
$briefDecodedSecond=json_decode($briefStoredSecond['brief'],true,12,JSON_THROW_ON_ERROR);
$ok($briefDecodedSecond['description']===$briefRevisedDescription &&
    $briefCanonical($briefDecodedSecond)===$briefCanonical(get_object_vars($briefRevised)) &&
    hash('sha256',$briefStoredSecond['html'])===$briefSecond['sha256'] &&
    str_contains($briefStoredSecond['html'],'&lt;strong&gt;Literal&lt;/strong&gt;') &&
    !str_contains($briefStoredSecond['html'],'<strong>Literal</strong>'),
    'revision preserves Unicode composition and CRLF while escaping literal markup');
$briefComparison=(new PrivatePreviewComparison($connections['a']['reader']))->compare(
    $companies['a'],$briefProjects['a'],1,2);
$ok($briefComparison['from']['brief']===$briefExpected['a'] &&
    $briefComparison['to']['brief']===get_object_vars($briefRevised) &&
    $briefComparison['changed_fields']===['description'] &&
    $briefComparison['from']['sha256']===$briefCreated['a']['sha256'] &&
    $briefComparison['to']['sha256']===$briefSecond['sha256'] &&
    $workspaces['a']['reader']->view($briefProjects['a'],$companies['a'],1)['html']===$briefHtml['a'],
    'comparison keeps original maximum-length bytes and both exact immutable digests');

$briefSnapshot=static fn():array=>[$historyDigest(),$admin->query(
    "SELECT md5(string_agg(row_to_json(p)::text,'' ORDER BY id)) FROM sites_preview.projects p"
)->fetchColumn()];
$briefBefore=$briefSnapshot();
try {
    (new PrivatePreviewHistory($connections['b']['reader']))->page($companies['b'],$briefProjects['a']);
    $ok(false,'another company unexpectedly read the Unicode briefing');
} catch (OutOfBoundsException $error) {
    $ok($error->getMessage()==='Private preview unavailable' && $briefSnapshot()===$briefBefore,
        'the real other-company reader cannot retrieve the known Unicode project');
}
try {
    $workspaces['b']['author']->createDemonstration('91000000-0000-4000-8000-000000000003',$companies['a'],
        PreviewBriefInput::parse(['product'=>'onepage']+$briefBoundary));
    $ok(false,'another company unexpectedly wrote the Unicode briefing');
} catch (PDOException $error) {
    $ok($error->getCode()==='42501' && $briefSnapshot()===$briefBefore,
        'the same valid maximum payload cannot authorize a cross-company write');
}

// Valid short fields isolate NUL from max-length and required-field failures.
// In particular, the benefit NUL precedes a line separator: trimming first
// would silently remove it and turn the invalid input into a persisted brief.
$briefShort=['product'=>'onepage','brand'=>'Marca fictícia','headline'=>'Chamada fictícia',
    'description'=>'Descrição sintética válida.','call_to_action'=>'Conheça',
    'benefits'=>"Primeiro benefício\nSegundo benefício"];
$briefNullCases=['brand'=>"\0Marca fictícia",'headline'=>"Chamada\0 fictícia",
    'description'=>"Descrição sintética\0",'call_to_action'=>"Conheça\0",
    'benefits'=>"Primeiro benefício\0\nSegundo benefício"];
foreach ($briefNullCases as $field=>$value) {
    $input=$briefShort;$input[$field]=$value;
    try {
        $workspaces['a']['author']->createDemonstration('91000000-0000-4000-8000-000000000004',$companies['a'],
            PreviewBriefInput::parse($input));
        $ok(false,'NUL briefing unexpectedly reached storage: '.$field);
    } catch (ValidationException $error) {
        $ok($error->errors()===[$field=>['Este texto contém caracteres inválidos. Digite novamente este campo.']] &&
            $briefSnapshot()===$briefBefore,
            'NUL receives the exact field error and creates no project/version/audit: '.$field);
    }
}
try {
    $workspaces['a']['author']->createDemonstration('91000000-0000-4000-8000-000000000005',$companies['a'],
        new SiteBrief('onepage','Marca fictícia','Chamada fictícia','Descrição sintética.',
            'Conheça',["Benefício\0 sintético"]));
    $ok(false,'direct domain NUL unexpectedly reached storage');
} catch (InvalidArgumentException $error) {
    $ok($error->getMessage()==='Invalid benefit list.' && $briefSnapshot()===$briefBefore,
        'direct domain callers also reject NUL before any SQL write');
}
echo 'BRIEF UNICODE SQL PASS: '.($count-$briefChecksBefore).
    " assertions; full Unicode boundaries for both products, JSONB and immutable digest round-trip, literal markup, tenant isolation and NUL refusal without writes\n";
