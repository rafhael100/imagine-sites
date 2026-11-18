<?php

declare(strict_types=1);

use Imagine\Sites\Publication\PgPublicationJournalGuard;

// Invoked only after #536 established two scoped, active read-only identities.
// No new SQL role, grant, principal or business record is introduced.
return static function (
    PDO $admin,
    string $password,
    array $cases,
    array $readers,
    callable $state,
): int {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
        getenv('APP_ENV') !== 'testing' ||
        getenv('SITES_PUBLICATION_REQUEST_READER_CI') !== '1' ||
        getenv('SITES_PUBLICATION_REQUEST_RECOVERY_CI') !== '1' ||
        !isset($cases['acknowledged']['expected'], $cases['company_b']['expected']) ||
        !isset($readers['a'])) throw new RuntimeException('Recovery CLI PG18 fixture opt-in required');

    $checks=0;
    $ok=static function(bool $yes,string $label) use (&$checks): void {
        if (!$yes) throw new RuntimeException('Recovery CLI PG18: '.$label);
        ++$checks;
    };
    $path='/run/secrets/publication_request_recovery';
    $passwordPath='/run/secrets/sites_publication_request_recovery_reader';
    $caPath='/run/secrets/sites_publication_request_recovery_ca';
    $outputDir='/var/lib/imagine-sites/publication-recoveries';
    $caExpected=getenv('RELEASE_REGISTRY_CA_FILE');
    $ok(is_string($caExpected) && is_file($caExpected) &&
        file_get_contents($caPath)===file_get_contents($caExpected) &&
        file_get_contents($passwordPath)===$password &&
        is_dir($outputDir) && (fileperms($outputDir)&0777)===0700,
        'fresh private directory, password and CA belong to verified registry lab');

    clearstatcache(true,$path);
    $st=lstat($path);
    $ok(is_array($st) && ($st['mode'] & 0777)===0600 &&
        ($st['mode']&0170000)===0100000 && $st['nlink']===1 &&
        $st['uid']===posix_geteuid(),'fixed manifest is a single 0600 file');
    $oid=(int)$admin->query('SELECT oid::bigint FROM pg_database WHERE datname=current_database()')->fetchColumn();
    $caSha=hash_file('sha256',$caPath);
    $target=['host'=>'localhost','port'=>55449,'database'=>'postgres',
        'databaseOid'=>$oid,'login'=>'pjrr_a_reader',
        'serverAddress'=>'127.0.0.1','caSha256'=>$caSha];
    $expected=$cases['acknowledged']['expected'];
    $ok($expected['technicalStatus']==='acknowledged' &&
        $expected['canPublish']===false &&
        $expected['publicationVerified']===false,
        'selected operation was committed before recovery fixture and is never an authority proof');

    $input=[
        'schema'=>'SITES_PUBLICATION_REQUEST_V1','target'=>$target,
        'siteRef'=>$expected['siteRef'],'releaseRef'=>$expected['releaseRef'],
        'expectedBindingRevision'=>$expected['bindingRevision'],
        'portalAcceptanceRef'=>$expected['portalAcceptanceRef'],
        'idempotencyKey'=>$expected['idempotencyKey'],
        'receiptRef'=>'ci-recovery-main',
    ];
    $write=static function (array $value) use ($path): void {
        clearstatcache(true,$path);$before=lstat($path);
        $fh=fopen($path,'r+b');
        if (!is_resource($fh) || !is_array($before)) throw new RuntimeException('Original private manifest missing');
        try {
            $bytes=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
            if (!ftruncate($fh,0) || !rewind($fh) || fwrite($fh,$bytes)!==strlen($bytes) ||
                !fflush($fh) || !fsync($fh)) throw new RuntimeException('Private fixture write refused');
        } finally {fclose($fh);}
        clearstatcache(true,$path);$after=lstat($path);
        if (!is_array($after) || $before['ino']!==$after['ino'] ||
            $before['dev']!==$after['dev'] || $before['uid']!==$after['uid'] ||
            ($after['mode']&0777)!==0600 || $after['nlink']!==1) {
            throw new RuntimeException('Manifest inode custody was lost');
        }
    };
    $run=static function(array $args): array {
        $script=dirname(__DIR__,3).'/ops/publication-candidate/recover-request.php';
        $pipes=[];
        $process=proc_open([getenv('PHP_BIN')?:PHP_BINARY,$script,...$args],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,3));
        if (!is_resource($process)) throw new RuntimeException('Recovery subprocess unavailable');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        return ['exit'=>proc_close($process),'out'=>$out,'err'=>$err];
    };
    $decode=static function(array $result): ?array {
        if ($result['exit']!==0||$result['err']!=='') return null;
        try {return json_decode($result['out'],true,8,JSON_THROW_ON_ERROR);}
        catch(Throwable){return null;}
    };
    $denied=static function(array $result,string $label) use($ok): void {
        $ok($result===['exit'=>1,'out'=>'','err'=>"PUBLICATION_RECOVERY_DENIED\n"],$label);
    };

    $baseline=$state();
    $write($input);
    $plan=$decode($run(['plan']));
    $ok(is_array($plan) && array_keys($plan)===
        ['schema','status','planSha256','expectedBindingRevision','canPublish','publicationVerified'] &&
        $plan['schema']==='SITES_PUBLICATION_REQUEST_RECOVERY_PLAN_V1' &&
        $plan['status']==='planned' && $plan['expectedBindingRevision']===$expected['bindingRevision'] &&
        $plan['canPublish']===false && $plan['publicationVerified']===false &&
        PgPublicationJournalGuard::digest($plan['planSha256']),
        'read-only plan commits original selectors without querying or creating a receipt');
    $ok(scandir($outputDir)===['.','..']&&$state()===$baseline,'planning leaves SQL rows/catalog and receipt directory empty');

    $denied($run(['recover','--plan-sha256='.str_repeat('f',64),
        '--authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED']),
        'wrong commitment refused before any receipt reservation');
    $ok(scandir($outputDir)===['.','..']&&$state()===$baseline,
        'wrong hash creates neither receipt nor journal write');

    $recover=$decode($run(['recover','--plan-sha256='.$plan['planSha256'],
        '--authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED']));
    $ok(is_array($recover) &&
        array_keys($recover)===['schema','status','planSha256','technicalStatus','revision',
            'attemptNumber','bindingRevision','artifactDigest','receiptDigest',
            'createdAt','updatedAt','canPublish','publicationVerified'] &&
        $recover['status']==='recovered' &&
        $recover['schema']==='SITES_PUBLICATION_REQUEST_RECOVERY_RESULT_V1' &&
        $recover['planSha256']===$plan['planSha256'] &&
        $recover['technicalStatus']===$expected['technicalStatus'] &&
        $recover['revision']===$expected['revision'] &&
        $recover['attemptNumber']===$expected['attemptNumber'] &&
        $recover['bindingRevision']===$expected['bindingRevision'] &&
        $recover['artifactDigest']===$expected['artifactDigest'] &&
        $recover['receiptDigest']===$expected['receiptDigest'] &&
        $recover['createdAt']===$expected['createdAt'] &&
        $recover['updatedAt']===$expected['updatedAt'] &&
        $recover['canPublish']===false && $recover['publicationVerified']===false &&
        !str_contains(json_encode($recover,JSON_THROW_ON_ERROR),$expected['operationRef']),
        'private lookup produces exact redacted state without operationRef on stdout');
    $receiptPath=$outputDir.'/ci-recovery-main.json';
    clearstatcache(true,$receiptPath);$receiptStat=lstat($receiptPath);
    $receipt=json_decode(file_get_contents($receiptPath),true,8,JSON_THROW_ON_ERROR);
    $ok(is_array($receiptStat)&&($receiptStat['mode']&0777)===0600&&
        $receiptStat['nlink']===1&&$receiptStat['uid']===posix_geteuid()&&
        $receipt===['schema'=>'SITES_PUBLICATION_REQUEST_RECOVERY_RECEIPT_V1',
            'planSha256'=>$plan['planSha256'],'operationRef'=>$expected['operationRef']],
        'exclusive 0600 private recovery receipt contains the exact persisted operation reference');
    $ok($state()===$baseline,'real plan/recovery appended no SQL operation or event and changed no catalog role');
    $denied($run(['recover','--plan-sha256='.$plan['planSha256'],
        '--authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED']),
        'reusing existing private receipt cannot overwrite history');
    $ok($receipt===json_decode(file_get_contents($receiptPath),true,8,JSON_THROW_ON_ERROR),
        'refusal preserves original recovered receipt bytes');

    $wrong=$input;
    $wrong['idempotencyKey']='ci-recovery-nonexistent';
    $wrong['receiptRef']='ci-recovery-wrong';
    $write($wrong);
    $wrongPlan=$decode($run(['plan']));
    $ok(is_array($wrongPlan)&&PgPublicationJournalGuard::digest($wrongPlan['planSha256'])&&
        $wrongPlan['planSha256']!==$plan['planSha256'],
        'different original idempotency key changes independent recovery plan commitment');
    $denied($run(['recover','--plan-sha256='.$wrongPlan['planSha256'],
        '--authorize-recovery=SITES_PUBLICATION_REQUEST_RECOVERY_ONLY_APPROVED']),
        'missing journal record does not create a simulated recovered operation');
    $wrongPath=$outputDir.'/ci-recovery-wrong.json';
    clearstatcache(true,$wrongPath);
    $ok(is_file($wrongPath)&&filesize($wrongPath)===0&&$state()===$baseline,
        'failed reader keeps an empty exclusive reservation and cannot alter the journal');

    $write($input);
    $last=$run(['plan']);
    $ok($decode($last)===$plan&&$state()===$baseline,
        'restoring exact input recovers identical planned commitment without mutation');
    echo "PUBLICATION REQUEST RECOVERY CLI PG PASS: {$checks} checks; real PG18/TLS reader, separate private receipt, redacted stdout, no SQL mutation.\n";
    return $checks;
};
