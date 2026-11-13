<?php

declare(strict_types=1);

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticExternalRevocationFence;

spl_autoload_register(static function (string $class): void {
    foreach ([
        'Imagine\\Sites\\Security\\' => 'Security',
        'Imagine\\Sites\\Integration\\' => 'Integration',
    ] as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $short = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $short)) {
            throw new RuntimeException('Invalid fixture class.');
        }
        require __DIR__.'/../src/'.$dir.'/'.$short.'.php';
        return;
    }
});

final class F355MemoryNonceStore implements ReplayStore
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
function f355Check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('F1-355: '.$description);
    ++$checks;
}
function f355Denied(callable $action): bool
{
    try { $action(); } catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}
$now = 1790000000;
$cmd = new SiteProjectCommand('1','op-f355-001','tenant-f355-a',
    'order-f355-001','contract-f355-001',3,'onepage','idem-f355-001');
$key = 'SYNTHETIC-PORTAL-SITES-KEY-NEVER-USE-IN-PRODUCTION';
$wire = SyntheticSignedCommand::sign(
    $cmd,$key,'nonce-f355-0001',$now-1,$now+60);
$store = new F355MemoryNonceStore();
$verified = SyntheticSignedCommand::verify($wire,$key,$now,$store);
function f355Proof(
    int $revision=3,
    bool $active=true,
    string $tenant='tenant-f355-a',
    string $contract='contract-f355-001',
    ?int $observedAt=null,
    ?int $expiresAt=null,
): SyntheticAuthoritySnapshot {
    global $now;
    return new SyntheticAuthoritySnapshot(
        $tenant,$contract,$revision,$active,$observedAt??$now-1,
        $expiresAt??$now+20,
    );
}
f355Check($verified->semanticFingerprint()===$cmd->semanticFingerprint(),
    'signed synthetic Portal command passes canonical verification');
SyntheticExternalRevocationFence::assertAdmissible(
    $verified,f355Proof(),f355Proof(),$now);
++$checks;
f355Check(f355Denied(fn()=>SyntheticSignedCommand::verify(
    $wire,$key,$now,$store)), 'signed replay cannot bypass nonce fence');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,null,f355Proof(),$now)), 'Portal authority unavailable: DENY');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(),null,$now)), 'out-of-band revocation source unavailable: DENY');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(2),f355Proof(3),$now)),
    'restored stale Portal revision cannot override independent source');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(3),f355Proof(4),$now)),
    'newer external revision blocks older signed Portal command');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(4),f355Proof(4),$now)),
    'a valid signature for old contract revision cannot override current revision');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(),f355Proof(3,false),$now)),
    'out-of-band revoked authority wins over Portal active flag');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(3,false),f355Proof(),$now)),
    'Portal revocation wins over independent active flag');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(3,true,'tenant-f355-b'),f355Proof(),$now)),
    'Portal tenant mismatch fails closed');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(),f355Proof(3,true,'tenant-f355-b'),$now)),
    'independent tenant mismatch fails closed');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(3,true,'tenant-f355-a','contract-f355-other'),f355Proof(),$now)),
    'Portal contract mismatch fails closed');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(),f355Proof(3,true,'tenant-f355-a','contract-f355-other'),$now)),
    'independent contract mismatch fails closed');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(3,true,'tenant-f355-a','contract-f355-001',$now-29,$now-1),
    f355Proof(),$now)), 'expired Portal evidence denied');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(),
    f355Proof(3,true,'tenant-f355-a','contract-f355-001',$now-29,$now-1),
    $now)), 'expired external evidence denied');
f355Check(f355Denied(fn()=>SyntheticExternalRevocationFence::assertAdmissible(
    $cmd,f355Proof(3,true,'tenant-f355-a','contract-f355-001',$now+1,$now+20),
    f355Proof(),$now)), 'future-dated snapshot denied');
f355Check(f355Denied(fn()=>f355Proof(
    3,true,'tenant-f355-a','contract-f355-001',$now-30,$now+1)),
    'snapshot lifetime over thirty seconds denied');
f355Check(f355Denied(fn()=>f355Proof(0)),
    'zero external authority revision denied');
f355Check(f355Denied(fn()=>f355Proof(
    3,true,'tenant other','contract-f355-001')),
    'malformed authority reference denied');

echo "$checks F1-355 synthetic Portal + independent-revocation admission checks passed.\n";
echo "NO-GO: offline snapshots cannot authenticate issuer, prove independent storage, or control real API admission.\n";
