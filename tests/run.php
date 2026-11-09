<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\Domain\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z]+$/D', $name)) throw new RuntimeException('Invalid class.');
    require __DIR__ . '/../src/Domain/' . $name . '.php';
});

use Imagine\Sites\Domain\DeliveryPolicy;
use Imagine\Sites\Domain\LeadDeadline;
use Imagine\Sites\Domain\PortalEvidence;
use Imagine\Sites\Domain\PublicationPolicy;
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Domain\SyntheticIdempotencyLedger;

$passed = 0;
function check(bool $condition, string $message): void { global $passed; if (!$condition) throw new RuntimeException('FAIL: ' . $message); ++$passed; echo "PASS $message\n"; }
function fixture(array $override = []): PortalEvidence {
    $data = array_replace([
        'tenantRef'=>'org-a','siteRef'=>'site-a','releaseRef'=>'release-003','manifestDigest'=>str_repeat('a',64),
        'acceptanceRef'=>'acceptance-a','contractEligible'=>true,'commercialProofVerified'=>true,
        'expressAcceptance'=>true,'acceptanceProofVerified'=>true,'authorizationCurrent'=>true,
        'domainAuthorized'=>true,'privacyReady'=>true,'securityReady'=>true,
    ], $override);
    return new PortalEvidence(...$data);
}
$manifest = new ReleaseManifest('org-a','site-a','release-003',str_repeat('a',64));
$pass = PublicationPolicy::evaluate($manifest,fixture());
check($pass->allowed, 'synthetic complete preflight accepted');
foreach ([
    'tenantRef'=>'tenant_mismatch','siteRef'=>'site_mismatch','releaseRef'=>'release_mismatch',
    'manifestDigest'=>'manifest_mismatch','acceptanceRef'=>'missing_acceptance_ref',
    'contractEligible'=>'contract_ineligible','commercialProofVerified'=>'commercial_proof_missing',
    'expressAcceptance'=>'express_acceptance_missing','acceptanceProofVerified'=>'acceptance_proof_unverified',
    'authorizationCurrent'=>'authorization_stale_or_revoked','domainAuthorized'=>'domain_unauthorized',
    'privacyReady'=>'privacy_not_ready','securityReady'=>'security_not_ready',
] as $field=>$expected) {
    $bad = match($field) {
        'manifestDigest'=>str_repeat('b',64), 'acceptanceRef'=>'',
        'tenantRef'=>'org-evil', 'siteRef'=>'site-b', 'releaseRef'=>'release-004',
        default=>false,
    };
    $decision = PublicationPolicy::evaluate($manifest,fixture([$field=>$bad]));
    check(!$decision->allowed && in_array($expected,$decision->reasons,true), 'deny '.$expected);
}
check(!PublicationPolicy::evaluate($manifest, fixture(), ['security_incident'])->allowed, 'open blocker denies');
check(!DeliveryPolicy::evaluate($manifest,$pass,false,str_repeat('a',64),true,true)->allowed, 'provider response alone not publication');
check(!DeliveryPolicy::evaluate($manifest,$pass,true,str_repeat('b',64),true,true)->allowed, 'wrong version cannot deliver');
check(!DeliveryPolicy::evaluate($manifest,$pass,true,str_repeat('a',64),false,true)->allowed, 'missing health cannot deliver');
check(!DeliveryPolicy::evaluate($manifest,$pass,true,str_repeat('a',64),true,false)->allowed, 'missing checklist cannot deliver');
check(DeliveryPolicy::evaluate($manifest,$pass,true,str_repeat('a',64),true,true)->allowed, 'complete synthetic delivery eligibility (NOT official ENTREGUE)');
$t0 = new DateTimeImmutable('2026-11-08T12:00:00Z');
$deadline = new LeadDeadline($t0);
check($deadline->initialEnd->format('Y-m-d H:i:s')==='2026-11-11 12:00:00','72h initial window');
check($deadline->deliveryDeadline->format('Y-m-d H:i:s')==='2026-11-26 12:00:00','432h deadline');
check(!$deadline->mayRetryAt($t0->modify('-1 second')), 'cannot retry before T0');
check($deadline->window($t0)==='initial', 'T0 start inclusive');
check($deadline->window($t0->modify('+72 hours'))==='recovery', '72h begins recovery');
check($deadline->mayRetryAt($t0->modify('+431 hours +59 minutes +59 seconds')), 'retry allowed before 432h');
check(!$deadline->mayRetryAt($t0->modify('+432 hours')), 'retry forbidden at 432h');
$ledger = new SyntheticIdempotencyLedger();
check($ledger->register('org-a','op-1',str_repeat('a',64)), 'first synthetic operation accepted');
check(!$ledger->register('org-a','op-1',str_repeat('a',64)), 'same request deduplicated');
check($ledger->register('org-b','op-1',str_repeat('a',64)), 'different tenant scoped separately');
$conflict = false;
try { $ledger->register('org-a','op-1',str_repeat('b',64)); } catch (LogicException) { $conflict=true; }
check($conflict,'conflicting idempotency payload rejected');
$invalid = false;
try { new ReleaseManifest('org-a','site-a','release-1','bad'); } catch (InvalidArgumentException) { $invalid = true; }
check($invalid,'invalid manifest hash rejected');
echo "\n$passed synthetic checks passed; NO infrastructure, browser or integration testing performed.\n";
