<?php
declare(strict_types=1);

// Real pinned Portal #1012 candidate encoder + Sites decoder. No official provider.
require_once __DIR__.'/../src/Production/BriefCandidateJson.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalLandingPageApprovedBriefEnvelopeCandidate.php';
require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../.pinned-portal/apps/portal/app/Domain/SitesIntegration/LandingPagePrivateBriefEnvelopeCandidate.php';

use App\Integration\PortalLandingPageApprovedBriefEnvelopeCandidate as SitesEnvelope;
use App\Domain\SitesIntegration\ApprovedLandingPageBriefHead as PortalHead;
use App\Domain\SitesIntegration\LandingPagePrivateBriefEnvelopeCandidate as PortalEncoder;

$t='tenant-A';$o='order-A';$s='11111111-1111-4111-8111-111111111111';
$c='contract-A';$ar='approval-A';$bytes='{"headline":"Landing Page apenas em ensaio","cta":"Contato"}';
$head=new PortalHead($t,$o,$s,$c,7,3,hash('sha256',$bytes),$ar,2,$bytes);
$wire=PortalEncoder::encode($head);
$checks=0;
$ok=static function(bool $ok,string $label)use(&$checks):void {
 if (!$ok)throw new RuntimeException('LANDING PRIVATE PAIR FAILURE: '.$label);++$checks;
};
$decode=static fn(string $raw,string $tenant=$t,string $order=$o,string $site=$s,
 string $contract=$c,int $revision=7,int $version=3,string $approval=$ar,int $epoch=2) =>
 SitesEnvelope::decode($raw,$tenant,$order,$site,$contract,$revision,$version,$approval,$epoch);
$deny=static function(callable $work,string $label)use($ok):void {
 ob_start();$bad=false;
 try{$work();}catch(RuntimeException $e){
  $bad=$e->getMessage()==='SITES_LANDING_PRIVATE_BRIEF_DENIED'&&$e->getPrevious()===null;
 }
 $output=ob_get_clean();$ok($bad&&$output==='', $label.' redacted');
};
$h=$decode($wire);
$ok($h->sourceBytes()===$bytes && hash('sha256',$h->sourceBytes())===$h->sourceSha256,
 'Portal original JSON bytes and digest preserved without reencoding');
$ok($h->sourceVersion===3&&$h->approvalEpoch===2&&$h->contractRevision===7,
 'source, epoch and contract revisions bound independently');
$ok($decode($wire)->sourceSha256===$h->sourceSha256,'exact replay is deterministic');
$ok(!property_exists($h,'canPublish')&&!property_exists($h,'clientAccepted')&&
 !property_exists($h,'authorityVerified'),'no payment, publication or acceptance authority');
try{json_encode($h,JSON_THROW_ON_ERROR);$bad=false;}catch(Throwable){$bad=true;}
$ok($bad,'no direct JSON serialization of private briefing');
try{serialize($h);$bad=false;}catch(Throwable){$bad=true;}
$ok($bad,'no PHP serialization of private briefing');
$ok(!str_contains(print_r($h,true),'Landing Page apenas em ensaio'),'debug redacts source bytes');
$deny(static fn()=>$decode($wire,'tenant-B'),'foreign tenant');
$deny(static fn()=>$decode($wire,$t,'order-B'),'foreign order');
$deny(static fn()=>$decode($wire,$t,$o,'22222222-2222-4222-8222-222222222222'),'foreign site');
$deny(static fn()=>$decode($wire,$t,$o,$s,'contract-B'),'foreign contract');
$deny(static fn()=>$decode($wire,$t,$o,$s,$c,8),'foreign contract revision');
$deny(static fn()=>$decode($wire,$t,$o,$s,$c,7,4),'stale source version');
$deny(static fn()=>$decode($wire,$t,$o,$s,$c,7,3,'approval-B'),'wrong approval');
$deny(static fn()=>$decode($wire,$t,$o,$s,$c,7,3,$ar,3),'revoked approval epoch');
$deny(static fn()=>$decode($wire,$t,$o,$s,$c,0),'invalid independent revision');
$deny(static fn()=>$decode($wire,$t,$o,'NOT-UUID'),'invalid independent site');
$deny(static fn()=>$decode(str_replace('"productCode":"landing_page"','"productCode":"onepage"',$wire)),
 'Onepage cannot masquerade as Landing');
$deny(static fn()=>$decode(str_replace('Landing Page apenas em ensaio','Landing Page modificada',$wire)),
 'changed original bytes without new sha');
$deny(static fn()=>$decode(str_replace('"sourceSha256":"'.$h->sourceSha256.'"',
 '"sourceSha256":"'.str_repeat('0',64).'"',$wire)),'forged source SHA-256');
$deny(static fn()=>$decode(substr($wire,0,-1).',"canPublish":true}'),'extra publishing grant');
$deny(static fn()=>$decode(substr($wire,0,-1).',"clientAccepted":true}'),'extra client acceptance');
$deny(static fn()=>$decode(substr($wire,0,-1).',"approvalEpoch":3}'),'duplicate JSON key');
$deny(static fn()=>$decode($wire."\n"),'alternate trailing whitespace');
$deny(static fn()=>$decode(str_replace('"sourceVersion":3','"sourceVersion":"3"',$wire)),
 'string version');
$deny(static fn()=>$decode(str_replace('"sourceVersion":3','"sourceVersion":3.0',$wire)),
 'non canonical numeric version');
$deny(static fn()=>$decode(str_replace('"schemaVersion":"portal.landing-approved-brief.handoff.candidate.v1"',
 '"schemaVersion":"sites.brief-snapshot.candidate.v0.1"',$wire)),'Onepage-like snapshot protocol');
$deny(static fn()=>$decode('{'),'malformed JSON');
$deny(static fn()=>$decode(''),'empty response');
$deny(static fn()=>$decode(str_repeat('x',400001)),'overlimit envelope');
$ok($decode($wire)->sourceBytes()===$bytes,'legitimate replay after all denials');
echo 'SITES LANDING PRIVATE APPROVED BRIEF PARITY PASS: '.$checks.
 " checks; synthetic Portal DTO only, no transactional approval, transport or release.\n";
