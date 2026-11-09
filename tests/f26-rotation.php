<?php

declare(strict_types=1);

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticRotationVerifier;
use Imagine\Sites\Security\SyntheticSignedCommand;

spl_autoload_register(static function (string $class): void {
    foreach (['Imagine\\Sites\\Security\\'=>'Security', 'Imagine\\Sites\\Integration\\'=>'Integration'] as $prefix=>$dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $short = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $short)) throw new RuntimeException('Bad class name');
        require __DIR__.'/../src/'.$dir.'/'.$short.'.php';
        return;
    }
});

final class F26MemoryReplay implements ReplayStore
{
    private array $used = [];
    public function rememberOnce(string $issuer, string $nonce, int $expiresAt): bool
    {
        $key = $issuer.':'.$nonce;
        if (isset($this->used[$key])) return false;
        $this->used[$key] = true;
        return true;
    }
}

$checks = 0;
function f26Check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$name);
    ++$checks;
    echo "PASS: $name\n";
}
function f26Deny(callable $run): bool
{
    try { $run(); } catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}

$old = 'OLD-SYNTHETIC-KEY-NEVER-PRODUCTION-0000000001';
$new = 'NEW-SYNTHETIC-KEY-NEVER-PRODUCTION-0000000002';
$other = 'OTHER-SYNTHETIC-KEY-NEVER-PRODUCTION-00000003';
$now = 1790000000;
$cmd = new SiteProjectCommand('1','op-f26-001','tenant-f26-a','order-f26-001',
    'contract-f26-001',3,'onepage','idem-f26-001');
$rotating = new SyntheticRotationVerifier($new,$old,$now,$now+120);
$oldWire = SyntheticSignedCommand::sign($cmd,$old,'nonce-f26-old-0001',$now-1,$now+60);
$newWire = SyntheticSignedCommand::sign($cmd,$new,'nonce-f26-new-0001',$now,$now+60);

f26Check($rotating->verify($newWire,$now,new F26MemoryReplay())->tenantRef === 'tenant-f26-a',
    'current credential valid');
f26Check($rotating->verify($oldWire,$now,new F26MemoryReplay())->tenantRef === 'tenant-f26-a',
    'pre-cutover old command accepted only in grace');
f26Check(f26Deny(fn()=> (new SyntheticRotationVerifier($new))->verify(
    $oldWire,$now,new F26MemoryReplay())), 'emergency revocation denies old key immediately');
f26Check(f26Deny(fn()=> $rotating->verify(
    SyntheticSignedCommand::sign($cmd,$old,'nonce-f26-late-0001',$now+1,$now+61),
    $now+1,new F26MemoryReplay())), 'old key cannot issue post-cutover commands');
f26Check(f26Deny(fn()=> (new SyntheticRotationVerifier($new,$old,$now,$now+30))
    ->verify($oldWire,$now,new F26MemoryReplay())),
    'old envelope cannot outlive retirement boundary');
f26Check(f26Deny(fn()=> $rotating->verify(
    SyntheticSignedCommand::sign($cmd,$other,'nonce-f26-unknown',$now,$now+60),
    $now,new F26MemoryReplay())), 'unknown signing credential denied');

$tampered = $oldWire;
$tampered['payload']['tenantRef'] = 'tenant-f26-b';
f26Check(f26Deny(fn()=> $rotating->verify($tampered,$now,new F26MemoryReplay())),
    'tampered tenant cannot authenticate');
f26Check(f26Deny(fn()=> $rotating->verify($oldWire,$now)),
    'missing durable replay store defaults to DENY');

$store = new F26MemoryReplay();
$rotating->verify($oldWire,$now,$store);
$reused = SyntheticSignedCommand::sign($cmd,$new,'nonce-f26-old-0001',$now,$now+60);
f26Check(f26Deny(fn()=> $rotating->verify($reused,$now,$store)),
    'same nonce cannot bypass replay by changing credential');
f26Check(f26Deny(fn()=> $rotating->verify($oldWire,$now,$store)),
    'same old command replay denied');

$wrongOrder = $newWire;
$wrongOrder = ['mac'=>$wrongOrder['mac']]+array_diff_key($wrongOrder,['mac'=>true]);
f26Check(f26Deny(fn()=> $rotating->verify($wrongOrder,$now,new F26MemoryReplay())),
    'reordered envelope denied');
f26Check(f26Deny(fn()=> new SyntheticRotationVerifier($new,$new,$now,$now+120)),
    'identical old and new key rejected');
f26Check(f26Deny(fn()=> new SyntheticRotationVerifier($new,$old,$now,$now+121)),
    'unbounded grace rejected');
f26Check(f26Deny(fn()=> new SyntheticRotationVerifier($new,null,$now,$now+120)),
    'window without previous key rejected');
f26Check(f26Deny(fn()=> new SyntheticRotationVerifier('weak')),
    'weak key rejected');
f26Check(f26Deny(fn()=> $rotating->verify($newWire,$now+60,new F26MemoryReplay())),
    'expired current credential denied at expiry');

echo "\n$checks F1-26 synthetic rotation checks passed. No vault, mTLS, API or production authority.\n";
