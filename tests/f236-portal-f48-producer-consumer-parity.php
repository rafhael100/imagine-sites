<?php

declare(strict_types=1);

/**
 * F1-236: test REAL pinned Portal F48 DTO vs Sites F1-235 independent
 * consumer. Both repos are checked out in GitHub Actions at known SHAs.
 * No Laravel bootstrap, provider, API, IAM, customer data or transport.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F236_PORTAL_PARITY')!=='1') {
    fwrite(STDERR,"F1-236 requires an opted-in public-source GitHub Actions job.\n");
    exit(1);
}

$portalRoot=getenv('SITES_F236_PORTAL_ROOT');
if (!is_string($portalRoot) || !is_dir($portalRoot) ||
    realpath($portalRoot)!==realpath(__DIR__.'/../portal-f48')) {
    throw new RuntimeException('Unexpected external repository test path.');
}
$source=$portalRoot.'/apps/portal/app/Domain/SiteIntake/SiteEngineHandoffPreviewEnvelope.php';
if (!is_file($source) || is_link($source)) {
    throw new RuntimeException('Pinned Portal F48 source unavailable.');
}
require_once $source;
require_once __DIR__.'/../src/Integration/PortalEditorialHandoffPreviewV1.php';

use App\Domain\SiteIntake\SiteEngineHandoffPreviewEnvelope as PortalProducer;
use Imagine\Sites\Integration\PortalEditorialHandoffPreviewV1 as SitesConsumer;

$checks=0;
function f236(bool $ok,string $description):void {
    global $checks;
    if (!$ok)throw new RuntimeException('F1-236 contract drift: '.$description);
    ++$checks;
}
function reject236(string $json):bool {
    try{SitesConsumer::fromCanonicalJson($json);}
    catch(DomainException){return true;}
    return false;
}
$seen=[];
foreach(['org-a','org-b'] as $org) {
    foreach(['sites_onepage','sites_landing_page'] as $product) {
        foreach([0,13] as $briefRev) {
            foreach([1,7] as $contentRev) {
                $source=new PortalProducer(
                    $org,
                    'DEMO-SITE-REQ-'.($org==='org-a'?'A':'B').$contentRev,
                    $product,
                    $briefRev,$contentRev,
                    hash('sha256',"f236\0".$org."\0".$product."\0".$briefRev."\0".$contentRev),
                    'DEMO-REVIEW-CMD-'.str_pad((string)$contentRev,3,'0',STR_PAD_LEFT),
                    1
                );
                $wire=$source->canonicalJson();
                $target=SitesConsumer::fromCanonicalJson($wire);
                f236($target->metadata===$source->fields(),
                    'the actual Portal DTO fields and Sites accepted fields are identical');
                f236($target->digest===$source->fingerprint(),
                    'the canonical JSON digest matches the actual Portal implementation');
                f236($target->sitesProductCode===($product==='sites_onepage'
                    ? 'onepage':'landing_page'),
                    'product enum maps exactly across repositories');
                f236(!$source->maySendToSitesEngine() && !$source->mayPublishSite()
                    && !$source->isVerifiedClientConsent() &&
                    !$target->canAuthenticatePortal() &&
                    !$target->canRecordClientApproval() &&
                    !$target->canCreateProject() && !$target->canSendToSites()
                    && !$target->canPublish() && !$target->canIndex()
                    && !$target->canWriteCrm(),
                    'both independent implementations forbid operational authority');

                $t=$source->fields();
                $t['authenticated_customer_approval']=true;
                f236(reject236(json_encode($t,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
                    'consumer denies a forged client-acceptance flag');
                $t=$source->fields();
                $t['publishing_enabled']=true;
                f236(reject236(json_encode($t,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
                    'consumer denies a forged site-publication flag');

                $seen[$target->digest]=true;
            }
        }
    }
}
f236(count($seen)===16,'each Portal variant has independent canonical evidence');

$real=new PortalProducer(
    'org-a','DEMO-SITE-REQ-A1','sites_onepage',0,1,
    str_repeat('a',64),'DEMO-REVIEW-CMD-001',1
);
$wire=$real->canonicalJson();
f236(reject236($wire."\n"),'extra newline is not accepted');
f236(reject236('{"extra":true,'.substr($wire,1)),'unknown first property rejected');
f236(reject236(str_replace('"source":"imagine-portal"',
    '"source":"untrusted"', $wire)),'source mismatch rejected');
f236(reject236(str_replace('"organization_demo":"org-a"',
    '"organization_demo":"org-b"', $wire))===false,
    'valid but different synthetic tenant must not be confused with authenticated identity');
f236(!SitesConsumer::fromCanonicalJson($wire)->canResolveRealTenant(),
    'even valid synthetic organization has no real tenant authority');

echo $checks." F1-236 independent Portal F48 producer/Sites consumer assertions passed.\n";
