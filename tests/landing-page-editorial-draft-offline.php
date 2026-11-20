<?php

declare(strict_types=1);

// Synthetic, offline cross-repo contract and draft-rendering tests. Not a client
// approval, operational origin, published page, or stored review decision.
$portal = __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/';
require_once $portal.'ApprovedLandingPageBriefHead.php';
require_once $portal.'LandingPagePrivateBriefEnvelopeCandidate.php';
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
foreach ([
    'PortalLandingPageAdmissionProjectionCandidate',
    'CurrentPortalLandingPageAdmissionSource',
    'DenyingCurrentPortalLandingPageAdmissionSource',
    'PortalLandingPageAdmissionReadbackCandidate',
    'PortalLandingPageApprovedBriefEnvelopeCandidate',
    'CurrentPortalLandingPageApprovedBriefSource',
    'DenyingCurrentPortalLandingPageApprovedBriefSource',
    'PortalLandingPageApprovedBriefReadbackCandidate',
    'PortalLandingPageEditorialDraftCandidate',
] as $name) {
    require_once __DIR__.'/../apps/sites/app/Integration/'.$name.'.php';
}

use App\Domain\SitesIntegration\ApprovedLandingPageBriefHead as PortalHead;
use App\Domain\SitesIntegration\LandingPagePrivateBriefEnvelopeCandidate as PortalWire;
use App\Integration\CurrentPortalLandingPageAdmissionSource;
use App\Integration\CurrentPortalLandingPageApprovedBriefSource;
use App\Integration\PortalLandingPageAdmissionReadbackCandidate;
use App\Integration\PortalLandingPageApprovedBriefReadbackCandidate;
use App\Integration\PortalLandingPageEditorialDraftCandidate;

$site='11111111-1111-4111-8111-111111111111';
$content = static fn(array $overrides=[]): string => json_encode(array_replace([
    'schemaVersion'=>'portal.landing-content.candidate.v1',
    'headline'=>'Imagine e cresça <img src=x onerror=alert(1)>',
    'subheadline'=>'Sua empresa com presença digital e resultado & confiança.',
    'benefits'=>['Identidade consistente','Contato organizado','Apresentação profissional'],
    'ctaLabel'=>'Conheça a proposta',
],$overrides), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$state=(object)[
    'bytes'=>$content(), 'version'=>1, 'calls'=>0,
    'cutoff'=>null, 'revoked'=>false,
];
$source=new class($state,$site) implements CurrentPortalLandingPageApprovedBriefSource {
    public function __construct(private object $state,private string $site) {}
    public function readCurrent(string $tenant,string $order,string $site):?string {
        if ($tenant!=='tenant-A' || $order!=='order-A' || $site!==$this->site) return null;
        ++$this->state->calls;
        if ($this->state->revoked ||
            ($this->state->cutoff!==null && $this->state->calls >= $this->state->cutoff)) {
            return null;
        }
        $version=$this->state->version;
        $head=new PortalHead(
            $tenant,$order,$site,'contract-A',7,$version,
            hash('sha256',$this->state->bytes),'approval-A-'.$version,$version,
            $this->state->bytes,
        );
        return PortalWire::encode($head);
    }
};
$admission=new class($site) implements CurrentPortalLandingPageAdmissionSource {
    public function __construct(private string $site) {}
    public function readAdmission(string $tenant,string $order,string $site):?string {
        if ($tenant!=='tenant-A' || $order!=='order-A' || $site!==$this->site) return null;
        return json_encode([
            'tenantRef'=>$tenant,'orderRef'=>$order,'siteRef'=>$site,
            'contractRef'=>'contract-A','contractRevision'=>7,
            'productCode'=>'landing_page',
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
};
$reader=new PortalLandingPageApprovedBriefReadbackCandidate(
    $source,new PortalLandingPageAdmissionReadbackCandidate($admission),
);
$draft=new PortalLandingPageEditorialDraftCandidate($reader);
$run=static function(
    ?string $digest=null, ?int $version=null, string $tenant='tenant-A',
    string $order='order-A',string $contract='contract-A',
)use($draft,$state,$site):array{
    $v=$version??$state->version;
    return $draft->prepareForInternalReview(
        $tenant,$order,$site,$contract,7,$v,'approval-A-'.$v,$v,
        $digest??hash('sha256',$state->bytes),
    );
};
$checks=0;
$ok=static function(bool $condition,string $label)use(&$checks):void{
    if (!$condition)throw new RuntimeException('LANDING DRAFT FIXTURE FAILED: '.$label);
    ++$checks;
};
$deny=static function(callable $callback,string $label)use($ok):void{
    ob_start();$denied=false;
    try{$callback();}catch(RuntimeException $e){
        $denied=$e->getMessage()==='SITES_LANDING_EDITORIAL_DRAFT_DENIED'
            && $e->getPrevious()===null;
    }
    $out=ob_get_clean();
    $ok($denied && $out==='', $label);
};

$packet=$run();
$review=json_decode($packet['reviewBytes'],true,8,JSON_THROW_ON_ERROR);
$ok(hash_equals(hash('sha256',$packet['artifactBytes']),$packet['artifactSha256']),
    'content-addressed actual private artifact');
$ok(strlen($packet['artifactBytes'])===$review['artifactSizeBytes'],
    'review length matches artifact bytes');
$ok($review['artifactSha256']===$packet['artifactSha256'] &&
    $review['sourceSha256']===hash('sha256',$state->bytes),
    'review bound to exact source and artifact');
$ok($review['tenantRef']==='tenant-A' && $review['orderRef']==='order-A' &&
    $review['productCode']==='landing_page' && $review['sourceVersion']===1,
    'review scoped to selectors and correct product');
$ok($review['state']==='pending_independent_internal_review' &&
    $review['portalActorAuthenticated']===false &&
    $review['internalReviewApproved']===false &&
    $review['clientAccepted']===false &&
    $review['canPublish']===false &&
    $review['canAcceptRealOrders']===false,
    'all operational authority remains explicitly denied');
$ok(!str_contains($packet['reviewBytes'],'onerror') &&
    !str_contains($packet['reviewBytes'],'Apresentação profissional'),
    'review pointer does not copy private source content');
$ok(str_contains($packet['artifactBytes'],'&lt;img src=x onerror=alert(1)&gt;') &&
    !str_contains($packet['artifactBytes'],'<img') &&
    !str_contains($packet['artifactBytes'],'<script') &&
    !str_contains($packet['artifactBytes'],'<form'),
    'untrusted headline escaped and no active scripts/forms');
$ok(str_contains($packet['artifactBytes'],'#f9941d') &&
    str_contains($packet['artifactBytes'],'noindex,nofollow,noarchive') &&
    str_contains($packet['artifactBytes'],'aria-disabled="true"'),
    'Imagine visual style, private indexing and inert CTA');
$ok(!str_contains($packet['artifactBytes'],'<a ') &&
    !str_contains($packet['artifactBytes'],'http:') &&
    !str_contains($packet['artifactBytes'],'https:'),
    'no clickable external destination or remote asset');
$ok($run()===$packet,'exact repeat is bytewise deterministic');
$ok($state->calls===8,'each render makes four independent head reads');
$ok(strlen($packet['reviewSha256'])===64 &&
    $packet['reviewSha256']!==$packet['artifactSha256'],
    'domain-separated review hash is distinct from artifact');

$deny(static fn()=>$run(null,null,'tenant-B'),'other tenant denied');
$deny(static fn()=>$run(null,null,'tenant-A','order-B'),'other order denied');
$deny(static fn()=>$run(null,null,'tenant-A','order-A','contract-B'),'other contract denied');
$deny(static fn()=>$run(str_repeat('0',64)),'false expected source hash denied');
$deny(static fn()=>$run(null,2),'unapproved version/epoch denied');

$original=$state->bytes;
$invalid=[
    ['wrong content product schema',$content(['schemaVersion'=>'portal.onepage.content.v1'])],
    ['extra authority field',$content(['canPublish'=>true])],
    ['missing required content',json_encode(['headline'=>'Olha só'],JSON_THROW_ON_ERROR)],
    ['HTML payload container',$content(['benefits'=>['<script>text</script>','Valid content']])],
    ['empty list',$content(['benefits'=>[]])],
    ['six benefits',$content(['benefits'=>array_fill(0,6,'Benefício')])],
    ['object instead of list',$content(['benefits'=>['x'=>'A vantagem']])],
    ['newline control',$content(['ctaLabel'=>"Enviar\nAgora"])],
    ['short headline',$content(['headline'=>'A'])],
    ['long CTA',$content(['ctaLabel'=>str_repeat('X',41)])],
    ['duplicate decoded JSON key',
        '{"schemaVersion":"portal.landing-content.candidate.v1","headline":"Primeira","headline":"Segunda","subheadline":"Descrição suficientemente grande","benefits":["Benefício"],"ctaLabel":"Comece"}'],
];
foreach($invalid as [$label,$raw]){
    $state->bytes=$raw;
    // HTML-looking strings are deliberately safe as TEXT; do not reject a
    // benefit merely for containing markup-looking characters.
    if ($label==='HTML payload container') {
        $special=$run();
        $ok(str_contains($special['artifactBytes'],'&lt;script&gt;text&lt;/script&gt;') &&
            !str_contains($special['artifactBytes'],'<script>'),
            'HTML-looking benefit is escaped, not executed');
        continue;
    }
    $deny(static fn()=>$run(),$label);
}
$state->bytes=$original;
$ok($run()===$packet,'restored source reproduces exact draft and review');

$state->calls=0;$state->cutoff=3;
$deny(static fn()=>$run(),'approval revocation observed between two complete reads');
$state->cutoff=null;$state->calls=0;$state->revoked=true;
$deny(static fn()=>$run(),'revoked Portal head denied');
$state->revoked=false;
$state->version=2;
$deny(static fn()=>$run(null,1),'old approved revision refused after newer head');
$new=$run();
$ok($new['reviewSha256']!==$packet['reviewSha256'] &&
    $new['artifactSha256']===$packet['artifactSha256'],
    'new approval version changes review identity without secretly changing HTML');
$ok($state->calls > 0,'candidate uses real Portal envelope source, not a constant fixture');

echo "SITES LANDING PRIVATE EDITORIAL DRAFT PASS: $checks checks; no live approval, review or publication.\n";
