<?php

declare(strict_types=1);

use App\Labs\Security\LabPdoReplayStore;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\SyntheticSignedCommand;

require __DIR__.'/../../vendor/autoload.php';

if (PHP_SAPI !== 'cli' || getenv('SITES_F1_14_LAB_ENABLED') !== '1') {
    throw new RuntimeException('F1-14 is strictly CI CLI testing.');
}
$dsn=(string)getenv('SITES_AUTH_LAB_DSN');
$user=(string)getenv('SITES_AUTH_LAB_USER');
$password=(string)getenv('SITES_AUTH_LAB_PASSWORD');
if (!str_contains($dsn,'dbname=sites_auth_replay_lab') || $user!=='sites_auth_runtime' ||
    strlen($password)<24) {
    throw new RuntimeException('F1-14 is missing isolated synthetic runtime credentials.');
}
$key='DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
$payload=json_decode(file_get_contents(__DIR__.'/../../../../fixtures/portal-sites/create-project.synthetic.json'),
    true,512,JSON_THROW_ON_ERROR);
$cmd=SiteProjectCommand::fromArray($payload);
$now=time();
$store=new LabPdoReplayStore($dsn,$user,$password);
$count=0;
function check(bool $ok,string $msg):void {
    global $count;
    if(!$ok)throw new RuntimeException('FAIL '.$msg);
    ++$count;
    echo 'PASS '.$msg."\n";
}
function rejected(callable $f):bool {
    try{$f();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}
$nonce='nonce-pg-adapter-test-01';
$wire=SyntheticSignedCommand::sign($cmd,$key,$nonce,$now,$now+60);
check(
    SyntheticSignedCommand::verify($wire,$key,$now,$store)->tenantRef==='tenant-demo-001',
    'valid signed command accepted by restricted SQL nonce adapter'
);
check(
    SyntheticSignedCommand::verify(
        SyntheticSignedCommand::sign($cmd,$key,'nonce-pg-adapter-test-02',$now,$now+60),
        $key,$now,$store
    )->orderRef==='order-demo-001',
    'restricted database accepts second distinct nonce'
);
check(rejected(fn()=>SyntheticSignedCommand::verify($wire,$key,$now,$store)),
    'same nonce denied by durable unique constraint');
$wrong=SyntheticSignedCommand::sign($cmd,$key,'nonce-pg-adapter-tamper',$now,$now+60);
$forged=$wrong;
$forged['payload']['tenantRef']='another-tenant';
check(rejected(fn()=>SyntheticSignedCommand::verify($forged,$key,$now,$store)),
    'tampering rejected before SQL consumption');
check(
    SyntheticSignedCommand::verify($wrong,$key,$now,$store)->tenantRef==='tenant-demo-001',
    'valid command can use nonce after earlier invalid MAC'
);
$outageStore=new LabPdoReplayStore(
    'pgsql:host=127.0.0.1;port=65432;dbname=sites_auth_replay_lab',
    $user,$password
);
$unavailable=SyntheticSignedCommand::sign($cmd,$key,'nonce-pg-db-down-001',$now,$now+60);
check(rejected(fn()=>SyntheticSignedCommand::verify($unavailable,$key,$now,$outageStore)),
    'database unavailable must fail closed (not allow)');
check(rejected(fn()=>SyntheticSignedCommand::verify($wire,$key,$now)),
    'no explicit replay adapter must still DENY');
echo "\n$count F1-14 PDO replay integration assertions passed; DB is runner-only, no real authorization.\n";
