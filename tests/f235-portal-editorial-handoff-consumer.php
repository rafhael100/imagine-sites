<?php

declare(strict_types=1);

require __DIR__.'/../src/Integration/PortalEditorialHandoffPreviewV1.php';

use Imagine\Sites\Integration\PortalEditorialHandoffPreviewV1 as Consumer;

$checks=0;
function ok235(bool $condition,string $why):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-235 '.$why);
    ++$checks;
}
function json235(array $f): string {
    return json_encode($f,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function denied235(string $json):bool {
    try {Consumer::fromCanonicalJson($json);}
    catch(DomainException){return true;}
    return false;
}

$base=[
    'schema'=>'imagine.portal.sites-handoff-preview.v1',
    'kind'=>'site_editorial_consistency_preview',
    'source'=>'imagine-portal',
    'target'=>'imagine-sites-engine',
    'organization_demo'=>'org-a',
    'site_reference_demo'=>'DEMO-SITE-REQ-A1',
    'product'=>'sites_onepage',
    'brief_revision'=>0,
    'content_revision'=>1,
    'content_sha256_demo'=>str_repeat('a',64),
    'review_command_demo'=>'DEMO-REVIEW-CMD-001',
    'review_revision'=>1,
    'review_status'=>'approved_demo',
    'authenticated_customer_approval'=>false,
    'external_delivery_enabled'=>false,
    'publishing_enabled'=>false,
];
foreach(['org-a','org-b'] as $org) {
    foreach(['sites_onepage','sites_landing_page'] as $product) {
        foreach([0,7,100000] as $briefRev) {
            $fields=$base;
            $fields['organization_demo']=$org;
            $fields['site_reference_demo']=$org==='org-a'
                ? 'DEMO-SITE-REQ-A1' : 'DEMO-SITE-REQ-B2';
            $fields['product']=$product;
            $fields['brief_revision']=$briefRev;
            $json=json235($fields);
            $v=Consumer::fromCanonicalJson($json);
            ok235($v->metadata===$fields,'field order & types preserved');
            ok235($v->digest===hash('sha256',$json),'SHA bytes exact');
            ok235($v->sitesProductCode===($product==='sites_onepage'
                ? 'onepage':'landing_page'),'explicit site product mapping');
            ok235(!$v->canAuthenticatePortal() && !$v->canCreateProject()
                && !$v->canRecordClientApproval() && !$v->canSendToSites()
                && !$v->canPublish() && !$v->canIndex()
                && !$v->canWriteCrm() && !$v->canResolveRealTenant(),
                'editorial DEMO must not claim operational power');
        }
    }
}
foreach([
    ['schema','sites.synthetic.create-project.v1'],
    ['kind','site_client_approved'],
    ['source','imagine-crm'],
    ['target','https://external.invalid'],
    ['organization_demo','org-c'],
    ['site_reference_demo','SITE-REAL'],
    ['product','sites_crm'],
    ['brief_revision',-1],
    ['brief_revision',100001],
    ['brief_revision','0'],
    ['content_revision',0],
    ['content_revision',100001],
    ['content_revision',false],
    ['content_sha256_demo','bad'],
    ['content_sha256_demo',str_repeat('A',64)],
    ['review_command_demo','REAL-CMD'],
    ['review_revision',2],
    ['review_revision','1'],
    ['review_status','customer_approved'],
    ['authenticated_customer_approval',true],
    ['external_delivery_enabled',true],
    ['publishing_enabled',true],
    ['publishing_enabled',0],
] as [$field,$value]) {
    $bad=$base;
    $bad[$field]=$value;
    ok235(denied235(json235($bad)),'invalid field rejected: '.$field);
}
$extra=$base;
$extra['auth_token']='fake-token';
ok235(denied235(json235($extra)),'unknown field denied');
$missing=$base;
unset($missing['source']);
ok235(denied235(json235($missing)),'missing field denied');
$wrongOrder=['target'=>$base['target']];
foreach($base as $key=>$value)if($key!=='target')$wrongOrder[$key]=$value;
ok235(denied235(json235($wrongOrder)),'reordered keys denied');
ok235(denied235(" \n".json235($base)),'whitespace is not canonical');
ok235(denied235(json235($base)."\n"),'trailing newline is not canonical');
ok235(denied235(str_replace('"review_revision":1,',
    '"review_revision":1,"review_revision":1,',json235($base))),
    'duplicate JSON key rejected');
ok235(denied235('[]'),'list denied');
ok235(denied235('{bad}'),'malformed JSON denied');
ok235(denied235(str_repeat('a',1025)),'oversized JSON denied');
ok235(denied235(''),'empty JSON denied');

echo "$checks F1-235 exact Portal F48 demo-only consumer assertions passed; no transport.\n";
