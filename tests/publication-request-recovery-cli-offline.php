<?php

declare(strict_types=1);

// This test never connects to SQL. Positive persistence is limited to a
// freshly-owned temporary *file*, not journal or external service state.
spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (str_starts_with($class,$prefix)) {
        $path=__DIR__.'/../src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});
use Imagine\Sites\Publication\{PrivatePublicationRequestRecovery, PrivatePublicationRequestReceipt};

$checks=0;
$ok=static function (bool $condition,string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline recovery failure: '.$label);
    ++$checks;
};
$run=static function (array $args,array $env=[]) use ($ok): array {
    $root=dirname(__DIR__); $script=$root.'/ops/publication-candidate/recover-request.php';
    $runtime=getenv('LD_LIBRARY_PATH');
    if (is_string($runtime) && $runtime!=='') $env['LD_LIBRARY_PATH']=$runtime;
    $pipes=[];
    $process=proc_open([PHP_BINARY,'-n','-d','open_basedir='.$root,$script,...$args],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);
    if (!is_resource($process)) throw new RuntimeException('Child unavailable');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$out,$err];
};
[$exit,$out,$err]=$run(['--help']);
$ok($exit===0 && $err==='' &&
    str_contains($out,'recover-request.php plan') &&
    str_contains($out,'--authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED') &&
    str_contains($out,'operationRef is never printed') &&
    str_contains($out,'Read-only PostgreSQL18'), 'help is non-operational and redacted');
$ci=['GITHUB_ACTIONS'=>'true','APP_ENV'=>'testing','SITES_PUBLICATION_REQUEST_RECOVERY_CI'=>'1'];
$hash='--plan-sha256='.str_repeat('a',64);
$approval='--authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED';
$deny=static function (array $args,array $env,string $label) use ($run,$ok): void {
    $ok($run($args,$env)===[1,'',"PUBLICATION_RECOVERY_DENIED\n"],$label);
};
foreach ([
    [], ['--help','recover'], ['recover','--help'], ['list'], ['inspect'], ['request'],
    ['retry'], ['apply'], ['reconcile'], ['publish'], ['record'], ['plan','extra'],
    ['plan',$hash], ['plan',$approval], ['recover'], ['recover',$hash], ['recover',$approval],
    ['recover',$hash,$hash], ['recover',$approval,$approval], ['recover',$hash,$approval,'extra'],
    ['recover','--plan-sha256=',$approval],
    ['recover','--plan-sha256='.str_repeat('A',64),$approval],
    ['recover','--plan-sha256='.str_repeat('a',63),$approval],
    ['recover','--plan-sha256='.str_repeat('a',65),$approval],
    ['recover','--plan-sha256='.str_repeat('g',64),$approval],
    ['recover','--authorize-recovery=SITES_PUBLICATION_REQUEST_ONLY_APPROVED',$hash],
    ['recover','--authorize-recovery=sites_publication_request_recovery_only_approved',$hash],
    ['recover','--receipt=/tmp/forbidden',$hash],
    ['recover','--password=DO_NOT_ECHO_CREDENTIAL',$approval],
    ['recover','--provider=DO_NOT_ECHO_PROVIDER',$approval],
] as $i=>$args) $deny($args,$ci,'argument rejection #'.$i);
foreach ([
    [], ['APP_ENV'=>'production'], ['APP_ENV'=>'local'], ['APP_ENV'=>'testing'],
    ['GITHUB_ACTIONS'=>'true','APP_ENV'=>'testing'],
    ['APP_ENV'=>'testing','SITES_PUBLICATION_REQUEST_RECOVERY_CI'=>'1'],
    ['GITHUB_ACTIONS'=>'true','APP_ENV'=>'staging','SITES_PUBLICATION_REQUEST_RECOVERY_CI'=>'1'],
    ['GITHUB_ACTIONS'=>'true','APP_ENV'=>'production','SITES_PUBLICATION_REQUEST_RECOVERY_CI'=>'1'],
    ['APP_ENV'=>'staging','SITES_PUBLICATION_REQUEST_RECOVERY_CI'=>'1'],
] as $i=>$env) {
    $deny(['plan'],$env,'closed plan environment #'.$i);
    $deny(['recover',$hash,$approval],$env,'closed recover environment #'.$i);
}
$deny(['plan'],$ci,'no synthesized plan without private mounts and PostgreSQL');
$deny(['recover',$hash,$approval],$ci,'no synthesized receipt or DB success');

$target=['host'=>'localhost','port'=>55449,'database'=>'postgres','databaseOid'=>1024,
    'login'=>'pj_recovery_a_reader','serverAddress'=>'127.0.0.1','caSha256'=>str_repeat('a',64)];
$valid=['schema'=>'SITES_PUBLICATION_REQUEST_V1','target'=>$target,
    'siteRef'=>'11111111-1111-4111-8111-111111111111','releaseRef'=>'synthetic-release',
    'expectedBindingRevision'=>2,'portalAcceptanceRef'=>'synthetic-acceptance',
    'idempotencyKey'=>'synthetic-key','receiptRef'=>'synthetic-private-recovered'];
$json=json_encode($valid,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$ok(PrivatePublicationRequestRecovery::parseManifest($json)===$valid,
    'reuses original request parser without target/case normalization');
foreach ([
    'duplicate'=>'{"schema":"SITES_PUBLICATION_REQUEST_V1","schema":"SITES_PUBLICATION_REQUEST_V1"}',
    'wrong schema'=>json_encode(array_replace($valid,['schema'=>'SITES_PUBLICATION_REQUEST_RECOVERY_V1']),JSON_THROW_ON_ERROR),
    'extra field'=>json_encode(array_replace($valid,['canPublish'=>true]),JSON_THROW_ON_ERROR),
    'target URL'=>json_encode(array_replace($valid,['target'=>array_replace($target,['host'=>'localhost;sslmode=disable'])]),JSON_THROW_ON_ERROR),
    'bad receipt ref'=>json_encode(array_replace($valid,['receiptRef'=>'../secret']),JSON_THROW_ON_ERROR),
] as $label=>$candidate) {
    try { PrivatePublicationRequestRecovery::parseManifest($candidate); $ok(false,$label.' accepted'); }
    catch (RuntimeException $e) {
        $ok($e->getMessage()==='PUBLICATION_RECOVERY_DENIED' && $e->getPrevious()===null,
            $label.' sanitized');
    }
}

// The existing exclusive-FD file writer now has two fixed incompatible schemas.
// A recovery must not be interpreted as a new original request or overwrite.
$parent=sys_get_temp_dir().'/sites-private-recovery-'.bin2hex(random_bytes(8));
$ok(mkdir($parent,0700),'created dedicated private temporary parent');
$plan=str_repeat('c',64);
$file=$parent.'/only-recovery.json';
try {
    PrivatePublicationRequestReceipt::checkAvailable($parent,'only-recovery');
    $receipt=PrivatePublicationRequestReceipt::reserveRecovery($parent,'only-recovery',$plan);
    $receipt->assertReserved();
    $payload=['schema'=>'SITES_PUBLICATION_REQUEST_RECOVERY_RECEIPT_V1',
        'planSha256'=>$plan,'operationRef'=>'22222222-2222-4222-8222-222222222222'];
    $receipt->write($payload);$receipt->close();
    $st=lstat($file);
    $ok(is_array($st)&&($st['mode']&0777)===0600&&$st['nlink']===1,
        'recovery file is exclusively owned mode0600 and a single hardlink');
    $ok(json_decode(file_get_contents($file),true,8,JSON_THROW_ON_ERROR)===$payload,
        'exact recovery receipt stored only in private file with UUID');
    try {PrivatePublicationRequestReceipt::reserveRecovery($parent,'only-recovery',$plan);$ok(false,'overwrote receipt');}
    catch(RuntimeException $e){$ok($e->getMessage()==='PUBLICATION_REQUEST_DENIED','existing receipt is never replaced');}

    $writer=PrivatePublicationRequestReceipt::reserve($parent,'separate-original',$plan);
    try {
        $writer->write($payload);$ok(false,'original writer accepted recovery schema');
    } catch(RuntimeException $e) {
        $ok($e->getMessage()==='PUBLICATION_REQUEST_DENIED','original receipt rejects recovery schema');
    } finally { $writer->close(); }
    $other=$parent.'/separate-original.json';
    $ok(file_exists($other)&&filesize($other)===0,'failed original receipt remains reserved, not rewritten');
} finally {
    foreach (['only-recovery.json','separate-original.json'] as $name) {
        if(is_file($parent.'/'.$name)) unlink($parent.'/'.$name);
    }
    rmdir($parent);
}
echo "PUBLICATION RECOVERY CLI OFFLINE PASS: {$checks} checks; no SQL result simulated.\n";
