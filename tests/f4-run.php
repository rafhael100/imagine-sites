<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^(Domain|Application|Integration)\\\\[A-Za-z]+$/D', $name)) {
        throw new RuntimeException('Invalid test class path.');
    }
    require __DIR__.'/../src/'.str_replace('\\', '/', $name).'.php';
});

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectAdmission;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\SyntheticProjectRegistry;

$checks = 0;
function verify(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    ++$checks; echo 'PASS '.$label."\n";
}
function expectInvalid(callable $run): bool {
    try { $run(); } catch (InvalidArgumentException) { return true; }
    return false;
}

final class AllowListedPortalFixture implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        return $command->tenantRef === 'tenant-demo-001' &&
            $command->contractRef === 'contract-demo-001' &&
            $command->contractRevision === 1 ?
            new PolicyDecision(true, []) : new PolicyDecision(false, ['fixture_not_authorized']);
    }
}

$payload = json_decode(file_get_contents(__DIR__.'/../fixtures/portal-sites/create-project.synthetic.json'), true, 512, JSON_THROW_ON_ERROR);
$schema = json_decode(file_get_contents(__DIR__.'/../contracts/portal-sites/v1/create-project.schema.json'), true, 512, JSON_THROW_ON_ERROR);
verify($schema['additionalProperties'] === false, 'schema forbids extra fields');
verify(count($schema['required']) === 8, 'schema required fields stable');
$cmd = SiteProjectCommand::fromArray($payload);
verify($cmd->productCode === 'onepage', 'fixture parse');
verify($cmd->contractRevision === 1, 'contract revision retained');
verify(!(new SiteProjectAdmission())->evaluate($cmd)->allowed, 'default authority denies every create');
verify((new SiteProjectAdmission(new AllowListedPortalFixture()))->evaluate($cmd)->allowed, 'allowlist mock accepts known identity (NOT production authority)');
verify(!(new SiteProjectAdmission(new AllowListedPortalFixture()))->evaluate($cmd, ['unpaid'])->allowed, 'blocker overrides mock allow');
foreach ([
    ['tenantRef'=>'other-tenant'], ['contractRef'=>'other-contract'], ['contractRevision'=>2],
] as $override) {
    $bad = SiteProjectCommand::fromArray(array_replace($payload,$override));
    verify(!(new SiteProjectAdmission(new AllowListedPortalFixture()))->evaluate($bad)->allowed, 'unauthorized mock identity blocked');
}
verify(expectInvalid(fn() => SiteProjectCommand::fromArray(array_replace($payload, ['productCode'=>'wordpress']))), 'unsupported product rejected');
verify(expectInvalid(fn() => SiteProjectCommand::fromArray(array_replace($payload, ['tenantRef'=>'../../etc/passwd']))), 'unsafe tenant ref rejected');
verify(expectInvalid(fn() => SiteProjectCommand::fromArray(array_replace($payload, ['contractRevision'=>0]))), 'zero contract revision rejected');
verify(expectInvalid(fn() => SiteProjectCommand::fromArray(array_replace($payload, ['contractRevision'=>'1']))), 'numeric string revision rejected');
verify(expectInvalid(fn() => SiteProjectCommand::fromArray(array_merge($payload, ['adminOverride'=>true]))), 'unexpected admin override rejected');
$missing = $payload; unset($missing['orderRef']);
verify(expectInvalid(fn() => SiteProjectCommand::fromArray($missing)), 'missing order reference rejected');
$registry = new SyntheticProjectRegistry();
verify($registry->register($cmd), 'first synthetic create accepted');
verify(!$registry->register($cmd), 'identical retry deduplicated');
$otherTenant = SiteProjectCommand::fromArray(array_replace($payload, ['tenantRef'=>'tenant-demo-002']));
verify($registry->register($otherTenant), 'tenant-scoped synthetic namespace');
$conflict = false;
try { $registry->register(SiteProjectCommand::fromArray(array_replace($payload, ['productCode'=>'landing_page']))); }
catch (LogicException) { $conflict=true; }
verify($conflict, 'same idempotency key with different payload rejects');
$otherOperation = SiteProjectCommand::fromArray(array_replace($payload, ['operationId'=>'op-demo-002', 'idempotencyKey'=>'idem-demo-002']));
$doubleSite = false;
try { $registry->register($otherOperation); } catch (LogicException) { $doubleSite=true; }
verify($doubleSite, 'different key cannot create same order twice');
verify($cmd->semanticFingerprint() === SiteProjectCommand::fromArray($payload)->semanticFingerprint(), 'stable semantic fingerprint');
echo "\n$checks F1-04 synthetic contract checks passed; no HTTP, DB, Portal connection, credentials or live authority.\n";
