<?php

declare(strict_types=1);

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticSignedAuthorityAdmission;
use Imagine\Sites\Security\SyntheticSignedCommand;

spl_autoload_register(static function (string $class): void {
    foreach ([
        'Imagine\\Sites\\Security\\' => 'Security',
        'Imagine\\Sites\\Integration\\' => 'Integration',
    ] as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $name = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $name)) {
            throw new RuntimeException('Invalid test class name.');
        }
        require __DIR__.'/../src/'.$dir.'/'.$name.'.php';
        return;
    }
});

final class F356Replay implements ReplayStore
{
    private array $seen = [];
    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool
    {
        $key = $issuer.':'.$nonce;
        if (isset($this->seen[$key])) return false;
        $this->seen[$key] = true;
        return true;
    }
}

$checks = 0;
function f356Check(bool $pass, string $label): void
{
    global $checks;
    if (!$pass) throw new RuntimeException('F1-356: '.$label);
    ++$checks;
}
function f356Denied(callable $run): bool
{
    try { $run(); } catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}

$now = 1790000000;
$keyCommand = 'LAB-COMMAND-KEY-DO-NOT-USE-IN-PRODUCTION-01';
$keyPortal = 'LAB-PORTAL-AUTHORITY-KEY-DO-NOT-USE-LIVE-02';
$keyExternal = 'LAB-EXTERNAL-REVOCATION-KEY-DO-NOT-USE-LIVE-03';
$command = new SiteProjectCommand('1','op-f356-a','tenant-f356-a',
    'order-f356-a','contract-f356-a',7,'landing_page','idem-f356-a');
$wire = SyntheticSignedCommand::sign(
    $command, $keyCommand, 'nonce-f356-a0001', $now-1, $now+60
);
function f356Proof(
    string $issuer,
    string $key,
    bool $active=true,
    int $revision=7,
    string $tenant='tenant-f356-a',
    string $contract='contract-f356-a',
    ?int $observed=null,
    ?int $expires=null,
): array {
    global $now;
    $snapshot = new SyntheticAuthoritySnapshot(
        $tenant, $contract, $revision, $active,
        $observed ?? $now-2, $expires ?? $now+20
    );
    return SyntheticAuthorityAttestation::sign($issuer, $snapshot, $key);
}
$portal = f356Proof('imagine-portal-authority', $keyPortal);
$external = f356Proof('imagine-independent-revocation', $keyExternal);
function f356Admit(
    array $wire,
    ?array $portal,
    ?array $external,
    ?F356Replay $replay=null,
    ?int $nowOverride=null,
    ?string $cmdKey=null,
    ?string $portalKey=null,
    ?string $externalKey=null,
): SiteProjectCommand {
    global $now,$keyCommand,$keyPortal,$keyExternal;
    return SyntheticSignedAuthorityAdmission::admit(
        $wire,$portal,$external,
        $cmdKey ?? $keyCommand, $portalKey ?? $keyPortal,
        $externalKey ?? $keyExternal, $nowOverride ?? $now,
        $replay ?? new F356Replay()
    );
}

$replay = new F356Replay();
$admitted = f356Admit($wire,$portal,$external,$replay);
f356Check($admitted->semanticFingerprint()===$command->semanticFingerprint(),
    'issuer-bound Portal + external proofs and signed command admitted');
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$external,$replay)),
    'nonce replay denied even with valid independent attestations');
f356Check(f356Denied(static fn()=>f356Admit($wire,null,$external)),
    'missing Portal proof denied');
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,null)),
    'missing external proof denied');

$changedPortal = $portal;
$changedPortal['active'] = false;
f356Check(f356Denied(static fn()=>f356Admit($wire,$changedPortal,$external)),
    'client cannot flip signed Portal active flag');
$changedExternal = $external;
$changedExternal['active'] = false;
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$changedExternal)),
    'client cannot flip signed external active flag');
$changedExternal = $external;
$changedExternal['revision'] = 99;
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$changedExternal)),
    'client cannot forge authority revision');

$reordered = ['mac'=>$portal['mac']]+array_diff_key($portal,['mac'=>true]);
f356Check(f356Denied(static fn()=>f356Admit($wire,$reordered,$external)),
    'noncanonical proof ordering denied');
$extra = $portal + ['superuser' => true];
f356Check(f356Denied(static fn()=>f356Admit($wire,$extra,$external)),
    'unknown signed-proof field denied');
$invalidType = $portal;
$invalidType['revision'] = '7';
f356Check(f356Denied(static fn()=>f356Admit($wire,$invalidType,$external)),
    'string instead of integer revision denied');
f356Check(f356Denied(static fn()=>f356Admit($wire,$external,$portal)),
    'cross-issuer proof substitution denied');
f356Check(f356Denied(static fn()=>f356Admit(
    $wire, $portal, $external, null, null, null, $keyExternal, $keyPortal)),
    'cross-service credential substitution denied');
f356Check(f356Denied(static fn()=>f356Admit(
    $wire, $portal, $external, null, null, null, $keyPortal)),
    'identical Portal and external authority keys denied');
f356Check(f356Denied(static fn()=>f356Admit(
    $wire, $portal, $external, null, null, null, $keyCommand)),
    'shared command and Portal credential denied');

$forged = f356Proof('imagine-portal-authority', 'UNTRUSTED-SYNTHETIC-KEY-NEVER-VALID-000000000');
f356Check(f356Denied(static fn()=>f356Admit($wire,$forged,$external)),
    'unknown authority key denied');
$revoked = f356Proof('imagine-independent-revocation',$keyExternal,false);
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$revoked)),
    'genuine signed revocation denies command');
$oldEpoch = f356Proof('imagine-independent-revocation',$keyExternal,true,6);
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$oldEpoch)),
    'restored older independent epoch denied');
$newEpoch = f356Proof('imagine-independent-revocation',$keyExternal,true,8);
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$newEpoch)),
    'newer external contract epoch denies stale command');
$otherTenant = f356Proof(
    'imagine-independent-revocation',$keyExternal,true,7,'tenant-f356-b');
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$otherTenant)),
    'signed but mismatched tenant denied');
$otherContract = f356Proof(
    'imagine-portal-authority',$keyPortal,true,7,'tenant-f356-a','contract-f356-b');
f356Check(f356Denied(static fn()=>f356Admit($wire,$otherContract,$external)),
    'signed but mismatched contract denied');
$stale = f356Proof(
    'imagine-independent-revocation',$keyExternal,true,7,
    'tenant-f356-a','contract-f356-a',$now-20,$now-1);
f356Check(f356Denied(static fn()=>f356Admit($wire,$portal,$stale)),
    'expired external authority signature denied');
$future = f356Proof(
    'imagine-portal-authority',$keyPortal,true,7,
    'tenant-f356-a','contract-f356-a',$now+1,$now+20);
f356Check(f356Denied(static fn()=>f356Admit($wire,$future,$external)),
    'future-dated Portal attestation denied');
f356Check(f356Denied(static fn()=>f356Admit(
    $wire,$portal,$external,null,$now+60)),
    'expired command denied even when proofs once were valid');
f356Check(f356Denied(static fn()=>f356Admit(
    $wire,$portal,$external,null,0)),
    'invalid authority verification clock denied');

echo "$checks F1-356 issuer-bound signed Portal/independent authority admission checks passed.\n";
echo "NO-GO: fixture signing keys are public, evidence is supplied by tests and no service or database is contacted.\n";
