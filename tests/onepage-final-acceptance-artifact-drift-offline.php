<?php
declare(strict_types=1);

use Imagine\Sites\Production\{
    BriefSnapshotInput, LocalSiteRendering, OnepageBuyerPrivatePreviewCandidate,
    OnepageExactFinalAcceptanceReadCandidate
};

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (str_starts_with($class, $prefix)) {
        $path = $root.'/src/Production/'.substr($class, strlen($prefix)).'.php';
        if (is_file($path)) require $path;
    }
});
$examples = $root.'/contracts/portal-sites/candidates/brief-production-v1/examples/';
$read = static function (string $name) use ($examples): string {
    $bytes = file_get_contents($examples.$name);
    if (!is_string($bytes)) throw new RuntimeException('MISSING_SYNTHETIC_FIXTURE');
    return $bytes;
};
$json = static fn(array $value): string => json_encode(
    $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
)."\n";

$source = $read('onepage-brief.synthetic.json');
$editorial = $read('render-input.synthetic.json');
$manifest = json_decode($read('brief-snapshot.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$manifest['payload']['sizeBytes'] = strlen($source);
$manifest['payload']['sha256'] = hash('sha256', $source);
$manifestBytes = $json($manifest);
$brief = BriefSnapshotInput::fromJson($manifestBytes, $source);
$plan = json_decode($read('brief-render-plan.synthetic.json'), true, 16, JSON_THROW_ON_ERROR);
$plan['snapshot']['manifestSha256'] = hash('sha256', $manifestBytes);
$plan['renderInput']['sizeBytes'] = strlen($editorial);
$plan['renderInput']['sha256'] = hash('sha256', $editorial);
$originalArtifact = LocalSiteRendering::render($editorial);
$plan['expectedArtifact'] = ['sha256'=>$originalArtifact->digest,'sizeBytes'=>$originalArtifact->sizeBytes];

$export = [
    'manifestBytes'=>$manifestBytes, 'sourceBytes'=>$source,
    'manifestSha256'=>hash('sha256', $manifestBytes),
    'payloadSha256'=>hash('sha256', $source),
    'authorityVerified'=>false, 'clientAccepted'=>false,
    'canPublish'=>false, 'canAcceptRealOrders'=>false,
];
$head = [
    'tenantRef'=>$brief->tenantRef, 'orderRef'=>$brief->orderRef, 'siteRef'=>$brief->siteRef,
    'contractRef'=>$brief->contractRef, 'contractRevision'=>$brief->contractRevision,
    'productCode'=>'onepage', 'snapshotRef'=>$brief->snapshotRef,
    'briefRevision'=>$brief->briefRevision, 'sourceVersion'=>$brief->sourceVersion,
    'manifestSha256'=>$brief->manifestSha256, 'payloadSha256'=>$brief->payloadSha256,
];
$confirmation = $head + [
    'buyerRef'=>'buyer-one', 'confirmationRef'=>'buyer-rights-1',
    'rightsDeclared'=>true, 'confirmed'=>true,
];
$review = $head + [
    'reviewerRef'=>'reviewer-other', 'approvalRef'=>'brief-review-1',
    'buyerConfirmationRef'=>'buyer-rights-1', 'approved'=>true,
];
$release = [
    'tenantRef'=>$brief->tenantRef, 'orderRef'=>$brief->orderRef, 'siteRef'=>$brief->siteRef,
    'sourceVersion'=>$brief->sourceVersion, 'briefRevision'=>$brief->briefRevision,
    'releaseRef'=>'release-a', 'artifactEpoch'=>1, 'htmlSha256'=>$originalArtifact->digest,
    'reviewerRef'=>'reviewer-other', 'reviewApprovalRef'=>'artifact-review-a', 'approved'=>true,
];
$accepted = $release + [
    'buyerRef'=>'buyer-one', 'acceptanceRef'=>'final-accept-a',
    'presentationRef'=>'presentation-a', 'explicitClick'=>true, 'revoked'=>false,
];
$presentation = [
    'tenantRef'=>$brief->tenantRef, 'orderRef'=>$brief->orderRef, 'siteRef'=>$brief->siteRef,
    'buyerRef'=>'buyer-one', 'presentationRef'=>'presentation-a',
    'releaseRef'=>'release-a', 'artifactEpoch'=>1,
    'htmlSha256'=>$originalArtifact->digest, 'briefRevision'=>$brief->briefRevision,
    'sourceVersion'=>$brief->sourceVersion, 'observed'=>true,
    // Synthetic in-memory bytes, NOT proof of an authenticated HTTP/browser observation.
    'servedHtmlBytes'=>$originalArtifact->html,
];
$initial = [
    'record'=>['export'=>$export,'plan'=>$json($plan),'editorial'=>$editorial,'buyerRef'=>'buyer-one'],
    'head'=>$head, 'confirmation'=>$confirmation, 'review'=>$review,
    'release'=>$release, 'acceptance'=>$accepted, 'presentation'=>$presentation,
];

// Reuse identical synthetic inputs in the R04 HTTP transport experiment,
// without rerunning R03's already-green regression suite.
if (defined('ONEPAGE_HTTP_R04_FIXTURES_ONLY')) return;

$gate = static function (array &$state): OnepageExactFinalAcceptanceReadCandidate {
    $preview = new OnepageBuyerPrivatePreviewCandidate(
        static fn() => $state['record'],
        static fn() => $state['head'],
        static fn() => $state['confirmation'],
        static fn() => $state['review'],
    );
    // The closures below are fixtures. No native login, legal writer,
    // authorized reviewer, durable receipt or production port is supplied.
    return new OnepageExactFinalAcceptanceReadCandidate(
        $preview,
        static fn() => $state['release'],
        static fn() => $state['acceptance'],
        static fn() => $state['presentation'],
    );
};
$call = static fn(OnepageExactFinalAcceptanceReadCandidate $checker, string $viewer='buyer-one') =>
    $checker->observe($viewer,$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion);

$checks=0;
$ok=static function(bool $value,string $case)use(&$checks):void {
    if (!$value) throw new RuntimeException('ONEPAGE_FINAL_TEST_REFUSED '.$case);
    ++$checks;
};
$deny=static function(callable $run,string $case)use($ok):void {
    ob_start(); $refused=false;
    try { $run(); } catch(RuntimeException $e) {
        $refused=$e->getMessage()==='ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED'
            && $e->getPrevious()===null;
    }
    $output=ob_get_clean();
    $ok($refused && $output==='',$case);
};
$deny(static fn()=>$call(new OnepageExactFinalAcceptanceReadCandidate()),'ports absent default deny');
$state=$initial;
$seen=$call($gate($state));
$ok($seen['clientFinalAccepted']===true && $seen['htmlSha256']===$originalArtifact->digest
    && $seen['finalAcceptanceRef']==='final-accept-a','exact synthetic receipt tied to renderer');
$ok($seen['privatePreview']===true && $seen['canPublish']===false
    && $seen['publicationAuthorized']===false,'acceptance never means publish');
$ok($call($gate($state))===$seen,'unchanged read is repeatable');
$deny(static fn()=>$call($gate($state),'buyer-two'),'other buyer same tenant');

$mutate=static function(callable $change,string $case)use($initial,$gate,$call,$deny):void {
    $fixture=$initial; $change($fixture);
    $deny(static fn()=>$call($gate($fixture)),$case);
};
$mutate(static function(array &$s):void { $s['acceptance']['explicitClick']=false; },'missing explicit click');
$mutate(static function(array &$s):void { $s['acceptance']['revoked']=true; },'revoked final acceptance');
$mutate(static function(array &$s):void { $s['acceptance']['buyerRef']='buyer-two'; },'borrowed acceptance');
$mutate(static function(array &$s):void { $s['acceptance']['artifactEpoch']=2; },'other artifact epoch');
$mutate(static function(array &$s):void { $s['acceptance']['sourceVersion']++; },'obsolete source version');
$mutate(static function(array &$s):void { $s['acceptance']['htmlSha256']=str_repeat('0',64); },'accepted other bytes');
$mutate(static function(array &$s):void { $s['release']['approved']=false; },'editorial approval revoked');
$mutate(static function(array &$s):void { $s['release']['reviewerRef']='buyer-one'; },'buyer self-approved artifact');
$mutate(static function(array &$s):void { $s['release']['htmlSha256']=str_repeat('f',64); },'current HTML digest drift');
$mutate(static function(array &$s):void { $s['release']['releaseRef']='release-other'; },'current release replaced');
$mutate(static function(array &$s):void { $s['review']['approved']=false; },'brief approval revoked in reused preview');
$mutate(static function(array &$s):void { $s['record']['buyerRef']='buyer-two'; },'preview ownership changed');
$mutate(static function(array &$s):void { $s['acceptance']['canPublish']=true; },'extra authority rejected');
$mutate(static function(array &$s):void { $s['presentation']['observed']=false; },'no independent observed presentation');
$mutate(static function(array &$s):void { $s['presentation']['buyerRef']='buyer-two'; },'presentation from other buyer in same tenant');
$mutate(static function(array &$s):void { $s['presentation']['htmlSha256']=str_repeat('a',64); },'browser presentation of other HTML bytes');
$mutate(static function(array &$s):void { $s['presentation']['artifactEpoch']=2; },'browser presentation of other generation');
$mutate(static function(array &$s):void { $s['presentation']['releaseRef']='release-other'; },'browser presentation from other release');
$mutate(static function(array &$s):void { $s['presentation']['presentationRef']='presentation-other'; },'unrelated browser presentation ref');
$mutate(static function(array &$s):void { $s['presentation']['sourceVersion']++; },'browser presentation from other briefing version');
$mutate(static function(array &$s):void { $s['presentation']=null; },'independent presentation missing');
$mutate(static function(array &$s):void { $s['presentation']['canPublish']=true; },'presentation cannot add publication authority');
// R03: a metadata-only witness may falsely claim the correct SHA even while
// holding different bytes. The read gate must hash and compare the actual
// captured HTML bytes, not trust the witness's copied digest field.
$mutate(static function(array &$s):void { $s['presentation']['servedHtmlBytes'].='!'; },'claimed hash correct but captured bytes changed');
$mutate(static function(array &$s):void { $s['presentation']['servedHtmlBytes']=''; },'empty served HTML evidence');
$mutate(static function(array &$s):void { $s['presentation']['servedHtmlBytes']=str_repeat('x',262145); },'oversized HTML evidence denied before digest');
$mutate(static function(array &$s):void { $s['presentation']['servedHtmlBytes']=false; },'nonstring observed bytes denied');
$mutate(static function(array &$s):void { unset($s['presentation']['servedHtmlBytes']); },'bytes absent while digest remains');



// Change ACTUAL editorial bytes, retaining source briefing provenance.
// The previous final click is stale even when the buyer's rights declaration
// for the original briefing remains the same.
$v2=$initial;
$copy=json_decode($editorial,true,16,JSON_THROW_ON_ERROR);
$copy['headline']='Nova redação fictícia revisada para a prévia privada';
$editorialB=$json($copy);
$artifactB=LocalSiteRendering::render($editorialB);
$ok($artifactB->digest!==$originalArtifact->digest &&
    $artifactB->html!==$originalArtifact->html,'real HTML bytes differ');
$planB=$plan;
$planB['releaseRef']='release-b';
$planB['renderInput']['sizeBytes']=strlen($editorialB);
$planB['renderInput']['sha256']=hash('sha256',$editorialB);
$planB['expectedArtifact']=['sha256'=>$artifactB->digest,'sizeBytes'=>$artifactB->sizeBytes];
$v2['record']['editorial']=$editorialB;
$v2['record']['plan']=$json($planB);
$v2['release']['releaseRef']='release-b';
$v2['release']['artifactEpoch']=2;
$v2['release']['htmlSha256']=$artifactB->digest;
$v2['release']['reviewApprovalRef']='artifact-review-b';
$deny(static fn()=>$call($gate($v2)),'v1 final acceptance invalid after v2 HTML edit');
$v2['acceptance']=$v2['release']+[
    'buyerRef'=>'buyer-one','acceptanceRef'=>'final-accept-b',
    'presentationRef'=>'presentation-b','explicitClick'=>true,'revoked'=>false,
];
$v2['presentation']=array_replace($presentation,[
    'presentationRef'=>'presentation-b', 'releaseRef'=>'release-b',
    'artifactEpoch'=>2, 'htmlSha256'=>$artifactB->digest,
    'servedHtmlBytes'=>$artifactB->html,
]);
$acceptedB=$call($gate($v2));
$ok($acceptedB['htmlSha256']===$artifactB->digest &&
    $acceptedB['finalAcceptanceRef']==='final-accept-b' &&
    $acceptedB['artifactEpoch']===2,'separate v2 click required, still cannot publish');
$deny(static fn()=>$call($gate($v2),'buyer-two'),'v2 other buyer denied');

// A rotation after the initial observation but during rendering fails closed.
$reads=0; $changing=$initial;
$changingGate=new OnepageExactFinalAcceptanceReadCandidate(
    new OnepageBuyerPrivatePreviewCandidate(
        static fn()=>$changing['record'],static fn()=>$changing['head'],
        static fn()=>$changing['confirmation'],static fn()=>$changing['review'],
    ),
    static function()use(&$reads,$changing):array {
        ++$reads;
        return $reads===1 ? $changing['release'] :
            array_replace($changing['release'],['artifactEpoch'=>2]);
    },
    static fn()=>$changing['acceptance'],
    static fn()=>$changing['presentation'],
);
$deny(static fn()=>$call($changingGate),'concurrent release rotation between reads');
$ok($reads===2,'release independently checked again after render');

// Browser presentation changes between two reads without rotating the release
// or the exact acceptance receipt: the independent witness must still refuse.
$presentReads=0; $steady=$initial;
$presentationRace=new OnepageExactFinalAcceptanceReadCandidate(
    new OnepageBuyerPrivatePreviewCandidate(
        static fn()=>$steady['record'],static fn()=>$steady['head'],
        static fn()=>$steady['confirmation'],static fn()=>$steady['review'],
    ),
    static fn()=>$steady['release'],
    static fn()=>$steady['acceptance'],
    static function()use(&$presentReads,$steady):array {
        ++$presentReads;
        return $presentReads===1 ? $steady['presentation'] :
            array_replace($steady['presentation'],['observed'=>false]);
    },
);
$deny(static fn()=>$call($presentationRace),'presentation revoked between two observations');
$ok($presentReads===2,'presentation independently checked after render');
// The witness can rotate only the served bytes after the first read while
// keeping all metadata and the receipt unchanged. The second read rejects it.
$bytesReads=0; $bytesSteady=$initial;
$bytesRace=new OnepageExactFinalAcceptanceReadCandidate(
    new OnepageBuyerPrivatePreviewCandidate(
        static fn()=>$bytesSteady['record'],static fn()=>$bytesSteady['head'],
        static fn()=>$bytesSteady['confirmation'],static fn()=>$bytesSteady['review'],
    ),
    static fn()=>$bytesSteady['release'],
    static fn()=>$bytesSteady['acceptance'],
    static function()use(&$bytesReads,$bytesSteady):array {
        ++$bytesReads;
        return $bytesReads===1 ? $bytesSteady['presentation'] :
            array_replace($bytesSteady['presentation'],[
                'servedHtmlBytes'=>$bytesSteady['presentation']['servedHtmlBytes'].'!',
            ]);
    },
);
$deny(static fn()=>$call($bytesRace),'observed HTML bytes drift between witness reads');
$ok($bytesReads===2,'witness bytes were independently re-read');
echo 'ONEPAGE FINAL ACCEPTANCE ARTIFACT DRIFT PASS: '.$checks." synthetic checks; NO-GO.\n";
