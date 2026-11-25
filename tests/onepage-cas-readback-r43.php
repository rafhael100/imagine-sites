<?php
declare(strict_types=1);

use Imagine\Sites\Production\{LocalSiteRendering, OnepageExactFinalAcceptanceReadCandidate};

// R42 creates the fixed fixture pin via restricted SQL and commits it.
// This is NOT a browser click, official Portal receipt, authority or writer.
require __DIR__.'/onepage-cas-entry-r42.php';

$r43=0;
$verify43=static function(bool $ok,string $label)use(&$r43):void {
    if (!$ok) throw new RuntimeException('R43 '.$label);
    ++$r43;
};
$admin=null;
$state43=$initial;
$state43['acceptance']['acceptanceRef']=$next['acceptanceRef'];
$reads43=0;
$presentations43=0;
$makeGate43=static function(PDO $db)use(&$state43,$makePreview,$readPin,&$reads43,&$presentations43):OnepageExactFinalAcceptanceReadCandidate {
    return new OnepageExactFinalAcceptanceReadCandidate(
        $makePreview($state43),
        static function()use(&$state43):array{return $state43['release'];},
        static function()use(&$state43):array{return $state43['acceptance'];},
        static function()use(&$state43,&$presentations43):array{
            ++$presentations43;return $state43['presentation'];
        },
        static function(string ...$keys)use($db,$readPin,&$reads43):?array{
            ++$reads43;return $readPin($db,$keys);
        },
    );
};
$args43=['buyer-one',$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion];
$gate43=$makeGate43($a);
$observe43=$gate43->observeHistoricallyBound(...$args43);
$verify43($observe43['finalAcceptanceRef']===$next['acceptanceRef'] &&
    $observe43['htmlSha256']===$originalArtifact->digest &&
    $observe43['clientFinalAccepted']===true && $observe43['canPublish']===false &&
    $observe43['publicationAuthorized']===false &&
    $reads43===2 && $presentations43===2,'restricted CAS append consumed by existing strict reader');

$deny43=static function(OnepageExactFinalAcceptanceReadCandidate $gate,string $label,int $pinReads)use($args43,$verify43,&$reads43,&$presentations43):void {
    $reads43=0;$presentations43=0;$denied=false;
    try {$gate->observeHistoricallyBound(...$args43);}
    catch(RuntimeException $e) {
        $denied=$e->getMessage()==='ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' && $e->getPrevious()===null;
    }
    $verify43($denied && $reads43===$pinReads && $presentations43===0,$label);
};
// The current fixture receipt is unchanged; the separately scoped SQL reader
// for another buyer or tenant must not be able to borrow the committed pin.
$deny43($makeGate43($b),'same-company SQL buyer cannot borrow CAS pin',1);
$deny43($makeGate43($c),'other tenant SQL reader cannot borrow CAS pin',1);

// Render genuinely different bytes through the existing pipeline. A stale
// receipt first fails against the new release; even a coherently substituted
// current receipt carrying the old acceptanceRef must fail at durable history.
$copy43=json_decode($editorial,true,16,JSON_THROW_ON_ERROR);
$copy43['headline']='Redação fictícia R43 revisada';
$editorial43=$json($copy43);
$artifact43=LocalSiteRendering::render($editorial43);
$verify43($artifact43->digest!==$originalArtifact->digest &&
    $artifact43->html!==$originalArtifact->html,'new real renderer bytes');
$plan43=$plan;
$plan43['releaseRef']='release-r43-v2';
$plan43['renderInput']['sizeBytes']=strlen($editorial43);
$plan43['renderInput']['sha256']=hash('sha256',$editorial43);
$plan43['expectedArtifact']=['sha256'=>$artifact43->digest,'sizeBytes'=>$artifact43->sizeBytes];
$state43['record']['editorial']=$editorial43;
$state43['record']['plan']=$json($plan43);
$state43['release']=array_replace($state43['release'],[
    'releaseRef'=>'release-r43-v2','artifactEpoch'=>2,
    'htmlSha256'=>$artifact43->digest,'reviewApprovalRef'=>'artifact-review-r43-v2',
]);
$deny43($gate43,'old persisted receipt cannot authorize changed release',0);
$state43['acceptance']=$state43['release']+[
    'buyerRef'=>'buyer-one','acceptanceRef'=>$next['acceptanceRef'],
    'presentationRef'=>'presentation-r43-v2','explicitClick'=>true,'revoked'=>false,
];
$state43['presentation']=array_replace($state43['presentation'],[
    'presentationRef'=>'presentation-r43-v2','releaseRef'=>'release-r43-v2',
    'artifactEpoch'=>2,'htmlSha256'=>$artifact43->digest,'servedHtmlBytes'=>$artifact43->html,
]);
$deny43($gate43,'coherent v2 fixture cannot repurpose immutable v1 CAS pin',1);
$state43['acceptance']['acceptanceRef']='missing-r43-v2-acceptance';
$deny43($gate43,'new reference without committed pin cannot authorize v2',1);

$verify43($readPin($a,$nextKeys)===$seen &&
    $readPin($a,$selector($pin))===$loaded &&
    (int)$a->query('SELECT count(*) FROM r36_lab.historical_pin')->fetchColumn()===5,
    'all history unchanged by rejected v2 observations');
echo "ONEPAGE R43 CAS TO STRICT READBACK PASS: {$r43} synthetic checks; NO-GO.\n";
