<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^(Integration|Security)\\\\[A-Za-z]+$/D', $name)) {
        throw new RuntimeException('Invalid class in contract test.');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;

final class MemoryNonceFixture implements ReplayStore
{
    /** @var array<string, bool> */
    private array $seen = [];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt): bool
    {
        $key=$issuer.':'.$nonce;
        if (isset($this->seen[$key])) return false;
        $this->seen[$key]=true;
        return true;
    }
}

$count=0;
function ok(bool $v,string $msg):void {global $count;if(!$v)throw new RuntimeException('FAIL '.$msg);$count++;echo 'PASS '.$msg."\n";}
function denied(callable $fn):bool {try{$fn();}catch(DomainException|InvalidArgumentException){return true;}return false;}
$key='DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
$now=1790000000;
$payload=json_decode(file_get_contents(__DIR__.'/../fixtures/portal-sites/create-project.synthetic.json'),true,512,JSON_THROW_ON_ERROR);
$cmd=SiteProjectCommand::fromArray($payload);
$env=SyntheticSignedCommand::sign($cmd,$key,'nonce-fixture-00000001',$now,$now+60);
ok($env['issuer']==='imagine-portal' && $env['audience']==='imagine-sites','fixed intended issuer/audience');
ok(strlen($env['mac'])===64,'sha256 mac present');
ok(denied(fn()=>SyntheticSignedCommand::verify($env,$key,$now)),'without durable verifier store default DENY');
$store=new MemoryNonceFixture();
$result=SyntheticSignedCommand::verify($env,$key,$now,$store);
ok($result->tenantRef==='tenant-demo-001','valid signed command decoded in fixture');
ok(denied(fn()=>SyntheticSignedCommand::verify($env,$key,$now,$store)),'replayed nonce rejected');
$mutated=$env;$mutated['payload']['tenantRef']='attacker-tenant';
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'tenant tampering rejected');
$mutated=$env;$mutated['payload']['productCode']='landing_page';
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'product tampering rejected');
$mutated=$env;$mutated['audience']='imagine-crm';
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'wrong audience rejected');
$mutated=$env;$mutated['issuer']='imagine-ai-agents';
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'unrecognized issuer rejected');
ok(denied(fn()=>SyntheticSignedCommand::verify($env,$key,$now+60,new MemoryNonceFixture())),'exact expiration boundary rejected');
ok(denied(fn()=>SyntheticSignedCommand::verify($env,$key,$now-6,new MemoryNonceFixture())),'premature command rejected');
ok(denied(fn()=>SyntheticSignedCommand::verify($env,'wrong-key-with-many-many-many-characters-00000',$now,new MemoryNonceFixture())),'wrong signing key rejected');
ok(denied(fn()=>SyntheticSignedCommand::sign($cmd,$key,'nonce-0001',$now,$now+121)),'TTL >120 seconds rejected');
ok(denied(fn()=>SyntheticSignedCommand::sign($cmd,'weak',$now.'-nonce', $now,$now+60)),'weak synthetic signing key rejected');
$mutated=$env;$mutated['adminOverride']=true;
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'unknown signed field rejected');
$mutated=$env;$mutated['payload']['adminOverride']=true;
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'unknown payload field rejected');
$mutated=$env;$mutated['issuedAt']='1790000000';
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'timestamp type confusion denied');
$mutated=$env;$mutated['mac']=str_repeat('0',64);
ok(denied(fn()=>SyntheticSignedCommand::verify($mutated,$key,$now,new MemoryNonceFixture())),'forged MAC rejected');
$new=SyntheticSignedCommand::sign($cmd,$key,'nonce-fixture-00000002',$now,$now+120);
ok(SyntheticSignedCommand::verify($new,$key,$now,new MemoryNonceFixture())->orderRef==='order-demo-001','maximum TTL boundary valid');

$portalFixture=json_decode(file_get_contents(__DIR__.'/../fixtures/portal-sites/create-project-v1.signed.synthetic.json'),
    true,512,JSON_THROW_ON_ERROR);
ok(SyntheticSignedCommand::verify($portalFixture,$key,$now,new MemoryNonceFixture())->contractRef==='contract-demo-001',
    'Portal independent signed fixture verifies in Sites contract');
$portalTamper=$portalFixture;
$portalTamper['payload']['contractRevision']=2;
ok(denied(fn()=>SyntheticSignedCommand::verify($portalTamper,$key,$now,new MemoryNonceFixture())),
    'Portal signed fixture cannot be changed to another contract revision');
echo "\n$count F1-11 lab signed-envelope checks passed. NO LIVE AUTHORIZATION.\n";
