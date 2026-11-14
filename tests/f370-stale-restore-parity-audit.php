<?php

declare(strict_types=1);

/**
 * F1-370: stale PG18 restore parity audit against a reference fixture
 * and Sites delivered outbox. The reference here is a more recent copy
 * in CI, NOT an independently trusted production authority.
 */
if(PHP_SAPI!=='cli' ||
   getenv('APP_ENV')!=='testing' ||
   getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F360_PG_LAB')!=='1' ||
   getenv('SITES_F367_PORTAL_DB_LAB')!=='1' ||
   getenv('SITES_F369_RESTORE_LAB')!=='1' ||
   getenv('SITES_F370_STALE_AUDIT_LAB')!=='1' ||
   getenv('DB_CONNECTION')!=='pgsql' ||
   getenv('DB_HOST')!=='127.0.0.1' ||
   getenv('DB_PORT')!=='5432' ||
   getenv('DB_DATABASE')!=='sites_f360_lab' ||
   getenv('DB_USERNAME')!=='postgres' ||
   getenv('DB_PASSWORD')!=='f360-fictional-admin-disposable-only') {
    fwrite(STDERR,"F1-370: CI-only stale backup audit denied.\n");
    exit(1);
}
$options=[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_TIMEOUT=>6,
];
function f370Database(string $port,string $database,string $password):PDO
{
    global $options;
    $connection=new PDO(
        'pgsql:host=127.0.0.1;port='.$port.';dbname='.$database.';connect_timeout=5',
        'postgres',$password,$options
    );
    if((string)$connection->query('SELECT current_database()')->fetchColumn()!==$database ||
       (int)$connection->query('SHOW server_version_num')->fetchColumn()<180000 ||
       (int)$connection->query('SHOW server_version_num')->fetchColumn()>=190000) {
        throw new RuntimeException('Unexpected database outside disposable PostgreSQL18.');
    }
    return $connection;
}
function f370Check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException('F1-370 FAIL: '.$label);
}
$sites=f370Database(
    '5432','sites_f360_lab','f360-fictional-admin-disposable-only'
);
$reference=f370Database(
    '5434','portal_f369_restore_lab','f369-fictional-restore-db-only'
);
$stale=f370Database(
    '5434','portal_f370_stale_lab','f369-fictional-restore-db-only'
);
function f370PortalRows(PDO $db):array
{
    $q=$db->query(
        'SELECT tenant_ref,operation_ref,event_type,command_digest
           FROM portal_f367.receipts ORDER BY tenant_ref,operation_ref'
    );
    $data=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $key=$record['tenant_ref']."\0".$record['operation_ref'];
        if(isset($data[$key]))throw new RuntimeException('Duplicate receipt identity.');
        $data[$key]=$record;
    }
    return $data;
}
/**
 * Compare a candidate database to a known, explicit reference snapshot,
 * after verifying each reference item is currently delivered at Sites.
 * Never silently declare an unknown/missing reference as fully recovered.
 */
function f370Audit(PDO $sites,array $expected, PDO $candidate):array
{
    if(count($expected)===0) {
        return ['ready'=>false,'missing'=>[],'mismatched'=>[],
            'unexpected'=>[],'unconfirmed'=>[],'reason'=>'missing_reference'];
    }
    $actual=f370PortalRows($candidate);
    $missing=[];$mismatched=[];$unexpected=[];$unconfirmed=[];
    $lookup=$sites->prepare(
        'SELECT command_digest,delivery_state FROM sites_f360.outbox
          WHERE tenant_ref=? AND operation_ref=?'
    );
    foreach($expected as $id=>$record) {
        $lookup->execute([$record['tenant_ref'],$record['operation_ref']]);
        $outbox=$lookup->fetch(PDO::FETCH_ASSOC);
        if(!is_array($outbox) ||
           $outbox['delivery_state']!=='delivered' ||
           $outbox['command_digest']!==$record['command_digest']) {
            $unconfirmed[]=$id;
        }
        if(!array_key_exists($id,$actual)) {
            $missing[]=$id;
            continue;
        }
        if($actual[$id]['event_type']!==$record['event_type'] ||
           $actual[$id]['command_digest']!==$record['command_digest']) {
            $mismatched[]=$id;
        }
    }
    foreach($actual as $id=>$record) {
        if(!array_key_exists($id,$expected))$unexpected[]=$id;
    }
    return [
      'ready'=>$missing===[] && $mismatched===[] &&
        $unexpected===[] && $unconfirmed===[],
      'missing'=>$missing,
      'mismatched'=>$mismatched,
      'unexpected'=>$unexpected,
      'unconfirmed'=>$unconfirmed,
      'reason'=>'parity',
    ];
}
$expected=f370PortalRows($reference);
f370Check(count($expected)===4,
    'reference third PG18 contains four receipts after F1-369 recovery');
$old=f370PortalRows($stale);
f370Check(count($old)===3,
    'deliberately stale logical backup contains only three receipts');
$healthy=f370Audit($sites,$expected,$reference);
f370Check($healthy['ready']===true &&
    $healthy['missing']===[] && $healthy['mismatched']===[] &&
    $healthy['unexpected']===[] && $healthy['unconfirmed']===[],
    'current restored PG18 has parity with all four Sites delivered events');

$staleReport=f370Audit($sites,$expected,$stale);
f370Check($staleReport['ready']===false &&
    count($staleReport['missing'])===1 &&
    count($staleReport['mismatched'])===0 &&
    count($staleReport['unexpected'])===0 &&
    count($staleReport['unconfirmed'])===0,
    'stale backup is blocked: one event marked delivered by Sites is missing');

f370Check(f370Audit($sites,[],$stale)['ready']===false,
    'empty authoritative reference cannot create false-positive approval');

$present=array_values($old)[0];
$mutate=$stale->prepare(
    'UPDATE portal_f367.receipts SET command_digest=?
      WHERE tenant_ref=? AND operation_ref=?'
);
$badDigest=hash('sha256','f370-fictional-tamper-not-real-data');
$mutate->execute([$badDigest,$present['tenant_ref'],$present['operation_ref']]);
f370Check($mutate->rowCount()===1,
    'only CI administrator can corrupt one stale fixture receipt');
$corrupt=f370Audit($sites,$expected,$stale);
f370Check($corrupt['ready']===false &&
    count($corrupt['missing'])===1 &&
    count($corrupt['mismatched'])===1 &&
    count($corrupt['unconfirmed'])===0,
    'restored backup with missing and altered event is rejected on both grounds');
f370Check(f370Audit($sites,$expected,$reference)['ready']===true,
    'mutating stale candidate does not affect healthy separate recovered DB');

$extra=$stale->prepare(
    'INSERT INTO portal_f367.receipts
       (tenant_ref,operation_ref,event_type,command_digest)
     VALUES (?,?,?,?)'
);
$extra->execute([
    'tenant-f370-rogue','operation-f370-rogue',
    'ProjectQueued',hash('sha256','f370-extra-untrusted-receipt')
]);
$unknown=f370Audit($sites,$expected,$stale);
f370Check($unknown['ready']===false &&
    count($unknown['missing'])===1 &&
    count($unknown['mismatched'])===1 &&
    count($unknown['unexpected'])===1,
    'unrecognized receipt on restored database is also quarantined');

echo "F1-370 PASS: complete restored snapshot matches Site ACKs; stale restore missing, altered or unknown receipt BLOCKED.\n";
echo "NO-GO: the trusted reference was provided by the fixture; production needs independent evidence, retention, AppSec and external backup validation.\n";
