<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^(Domain|Application)\\\\[A-Za-z]+$/D', $name)) throw new RuntimeException('Invalid class.');
    require __DIR__ . '/../src/' . str_replace('\\', '/', $name) . '.php';
});

use Imagine\Sites\Application\PortalAuthorityGateway;
use Imagine\Sites\Application\PublicationPreflightService;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Domain\PortalEvidence;
use Imagine\Sites\Domain\ReleaseManifest;

$passed = 0;
function chk(bool $ok,string $label): void { global $passed; if (!$ok) throw new RuntimeException("FAIL $label"); ++$passed; echo "PASS $label\n"; }
final class MockAllowingAuthority implements PortalAuthorityGateway
{
    public function verify(ReleaseManifest $manifest, PortalEvidence $evidence): PolicyDecision
    {
        return new PolicyDecision(true, []); // Only test fixture; NOT a real trust validator.
    }
}
$manifest = new ReleaseManifest('tenant-a','site-a','release-001',str_repeat('a',64));
function ev(array $replacements=[]): PortalEvidence {
    return new PortalEvidence(...array_replace([
      'tenantRef'=>'tenant-a','siteRef'=>'site-a','releaseRef'=>'release-001',
      'manifestDigest'=>str_repeat('a',64),'acceptanceRef'=>'acceptance-fixture',
      'contractEligible'=>true,'commercialProofVerified'=>true,
      'expressAcceptance'=>true,'acceptanceProofVerified'=>true,
      'authorizationCurrent'=>true,'domainAuthorized'=>true,
      'privacyReady'=>true,'securityReady'=>true
    ], $replacements));
}
$denied = (new PublicationPreflightService())->evaluate($manifest, ev());
chk(!$denied->allowed, 'default denies without trusted Portal gateway');
chk(in_array('trusted_portal_authority_unavailable',$denied->reasons,true), 'default denial has explicit reason');
chk(!(new PublicationPreflightService())->evaluate($manifest, ev(['acceptanceProofVerified'=>true]))->allowed, 'caller-provided true flags cannot bypass boundary');
$mock = new PublicationPreflightService(new MockAllowingAuthority());
chk($mock->evaluate($manifest,ev())->allowed, 'mocked authority positive fixture (not operational)');
chk(!$mock->evaluate($manifest,ev(['tenantRef'=>'tenant-b']))->allowed, 'mock still applies tenant gate');
chk(!$mock->evaluate($manifest,ev(['manifestDigest'=>str_repeat('b',64)]))->allowed, 'mock still applies release digest gate');
chk(!$mock->evaluate($manifest,ev(['authorizationCurrent'=>false]))->allowed, 'mock still applies revocation gate');
chk(!$mock->evaluate($manifest,ev(),['pending_security'])->allowed, 'mock still applies blockers');
echo "\n$passed F1-02 boundary checks passed (only synthetic mocks).\n";
