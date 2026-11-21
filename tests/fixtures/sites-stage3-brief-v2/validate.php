<?php
declare(strict_types=1);

namespace SitesStage3BriefV2;

use Closure;
use PDO;
use PDOException;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use SitesStage3Fixture\Stage3ReceiptChannel;

// Test only. Execute only as the predetermined non-root child of a qualified
// fresh hosted supervisor. Never invoke directly or with any external DSN.
const DB='portal_native_orders_ci';
const USER='portal_native_test_bootstrap';
const PORT=55483;
const NS='portal_onepage_brief_candidate';
const SEAL='sites-stage3-brief-v2-sql-witness.v1';
const DDL_SHA='91f2fc10039ae1ef2fc326eb770de3825a747bcc94c31cd72470144f63dbf421';
const RECEIVER_SHA='ee5e124be0521f44af7114ecd291c262ae544e6e448371d34f065973536426ba';
const OPTIONS=[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
    PDO::ATTR_PERSISTENT=>false,
    PDO::ATTR_STRINGIFY_FETCHES=>false,
    PDO::ATTR_TIMEOUT=>3,
];
const IDENTITY=<<<'SQL'
SELECT pg_catalog.current_database() AS db,
       session_user::text AS login,current_user::text AS actor,
       pg_catalog.host(pg_catalog.inet_server_addr()) AS server_host,
       pg_catalog.host(pg_catalog.inet_client_addr()) AS client_host,
       pg_catalog.inet_server_port()::text AS port,
       pg_catalog.current_setting('listen_addresses') AS listen,
       pg_catalog.current_setting('server_version_num') AS pg_version,
       pg_catalog.current_setting('data_directory') AS data_dir,
       extract(epoch FROM pg_catalog.pg_postmaster_start_time())::text AS postmaster_start,
       (SELECT system_identifier::text FROM pg_catalog.pg_control_system()) AS system_id,
       (SELECT oid::text FROM pg_catalog.pg_database WHERE datname=pg_catalog.current_database()) AS oid,
       (SELECT rolsuper AND rolcanlogin FROM pg_catalog.pg_roles WHERE rolname=current_user) AS admin,
       pg_catalog.pg_is_in_recovery() AS recovering,
       pg_catalog.current_setting('role') AS role,
       pg_catalog.current_setting('client_encoding') AS client_encoding,
       pg_catalog.current_setting('server_encoding') AS server_encoding,
       pg_catalog.current_setting('transaction_read_only') AS read_only,
       pg_catalog.pg_backend_pid()::text AS backend_pid,
       (SELECT extract(epoch FROM backend_start)::text FROM pg_catalog.pg_stat_activity
         WHERE pid=pg_catalog.pg_backend_pid() AND backend_type='client backend') AS backend_start
SQL;

function need(bool $ok): void { if (!$ok) throw new RuntimeException('REFUSED'); }
function marker(string $text): void { fwrite(STDOUT,$text."\n"); }
function readPipe(string $fd) {
    need(preg_match('/\A(?:[3-9]|[1-9][0-9]{1,5})\z/D',$fd)===1);
    $target=readlink('/proc/self/fd/'.$fd);
    need(is_string($target) && preg_match('/\Apipe:\[([1-9][0-9]*)\]\z/D',$target,$inode)===1);
    $info=file_get_contents('/proc/self/fdinfo/'.$fd,false,null,0,1024);
    need(is_string($info) && preg_match('/^flags:\s*([0-7]{1,12})$/m',$info,$flags)===1
        && (octdec($flags[1])&3)===0);
    $pipe=fopen('php://fd/'.$fd,'rb');
    need(is_resource($pipe));
    $s=fstat($pipe);
    need(is_array($s) && ($s['mode']&0170000)===0010000 && $s['uid']===0 && (string)$s['ino']===$inode[1]);
    return $pipe;
}
function epoch(mixed $s): bool {
    return is_string($s) && preg_match('/\A[1-9][0-9]*\.[0-9]{6}\z/D',$s)===1;
}
function positive(mixed $s): bool {
    return is_string($s) && preg_match('/\A[1-9][0-9]{0,9}\z/D',$s)===1;
}
function live(array $w): void {
    $now=time();
    need($now >= $w['issued_at']-2 && $now <= $w['issued_at']+120
        && readlink('/proc/self/ns/net')===$w['netns']);
}
function unpackWitness(array $r,string $run,string $outerNs): array {
    need(sodium_crypto_sign_verify_detached($r['signature'],$r['json'],$r['trustedRunnerKey']));
    $w=json_decode($r['json'],true,12,JSON_THROW_ON_ERROR);
    need(is_array($w) && json_encode($w,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)===$r['json']
        && array_keys($w)===['schema','nonce','issued_at','netns','postmaster_pid','data_dir',
            'port','pg_version','start','system_id','oids']
        && $w['schema']===SEAL && is_string($w['nonce']) && hash_equals($r['trustedNonce'],$w['nonce'])
        && is_int($w['issued_at']) && is_string($w['netns']) && $w['netns']!==$outerNs
        && preg_match('/\Anet:\[[1-9][0-9]*\]\z/D',$w['netns'])===1
        && is_int($w['postmaster_pid']) && $w['postmaster_pid']>1
        && $w['data_dir']===$run.'/pgdata' && $w['port']===PORT && $w['pg_version']==='180007'
        && epoch($w['start']) && is_string($w['system_id'])
        && preg_match('/\A[1-9][0-9]{14,19}\z/D',$w['system_id'])===1
        && is_array($w['oids']) && array_keys($w['oids'])===[DB] && positive($w['oids'][DB]));
    live($w);
    return $w;
}
function bytes(string $p,string $sha,int $limit): string {
    need(realpath($p)===$p && is_file($p) && !is_link($p));
    $s=file_get_contents($p,false,null,0,$limit+1);
    need(is_string($s) && strlen($s)>0 && strlen($s)<=$limit && hash_equals($sha,hash('sha256',$s)));
    return $s;
}
function connectedAdmin(#[SensitiveParameter] string $pw,array $w): PDO {
    live($w);
    $pdo=new PDO('pgsql:host=127.0.0.1;port='.PORT.';dbname='.DB.';sslmode=disable;gssencmode=disable;connect_timeout=3',
        USER,$pw,OPTIONS);
    need($pdo::class===PDO::class && !$pdo->inTransaction()
        && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
        && $pdo->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
        && $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false
        && $pdo->getAttribute(PDO::ATTR_PERSISTENT)===false
        && $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)===false);
    return $pdo;
}
function selectRows(PDO $db,string $sql,array $bind=[]): array {
    $q=$db->prepare($sql);
    need($q->execute($bind));
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    need($q->closeCursor());
    return $rows;
}
function one(PDO $db,string $sql,array $bind=[]): array {
    $r=selectRows($db,$sql,$bind);
    need(count($r)===1);
    return $r[0];
}
function effectiveIdentity(PDO $db,array $w): array {
    live($w);
    need(!$db->inTransaction() && $db->beginTransaction());
    try {
        $db->exec('SET TRANSACTION READ ONLY; SET LOCAL statement_timeout=3000');
        $r=one($db,IDENTITY);
        $want=['db'=>DB,'login'=>USER,'actor'=>USER,'server_host'=>'127.0.0.1',
            'client_host'=>'127.0.0.1','port'=>(string)PORT,'listen'=>'127.0.0.1',
            'pg_version'=>'180007','data_dir'=>$w['data_dir'],'postmaster_start'=>$w['start'],
            'system_id'=>$w['system_id'],'oid'=>$w['oids'][DB],'admin'=>true,'recovering'=>false,
            'role'=>'none','client_encoding'=>'UTF8','server_encoding'=>'UTF8','read_only'=>'on'];
        need(count($r)===count($want)+2);
        foreach ($want as $k=>$v) need(($r[$k]??null)===$v);
        need(positive($r['backend_pid']??null) && $r['backend_pid']!==(string)$w['postmaster_pid']
            && epoch($r['backend_start']??null));
        need($db->commit());
        live($w);
        return [$r['backend_pid'],$r['backend_start']];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

const ORG='org.synthetic';
const ORDER='order.synthetic';
const SITE='8b70f7b4-bc5b-4d86-b65b-1ec01ed6d72d';
const CONTRACT='contract.synthetic';
const BUYER='subject.synthetic.buyer';
const REVIEWER='subject.synthetic.reviewer';
// A second identity is deliberately neutral: this proves no revoke authority.
const REVOKE_FIXTURE='subject.synthetic.revoke.fixture';
const SNAP_A='563cced6-4344-4162-b42b-adb8837f1fb4';
const SNAP_B='4cd0229d-a786-4513-bf89-5ac1878d3caf';
const BODY_A="{\r\n\"corpo\":\"Conteúdo sintético A\"\r\n}\r\n";
const BODY_B="{\r\n\"corpo\":\"Conteúdo sintético B alterado\"\r\n}\r\n";
function bodyFor(int $version): string { return match($version) { 0=>BODY_A, 1=>BODY_B }; }
const SCOPED=[ORG,ORDER,SITE];

function effect(PDO $db,string $sql,array $bind=[]): int {
    $q=$db->prepare($sql);
    need($q->execute($bind));
    $n=$q->rowCount();
    need($q->closeCursor());
    return $n;
}
function revision(PDO $db,int $v,string $snapshot,string $confirmation): void {
    $source=bodyFor($v);
    $sha=hash('sha256',$source);
    need(effect($db,<<<'SQL'
INSERT INTO portal_onepage_brief_candidate.confirmed_revision
 (tenant_ref,order_ref,site_ref,source_version,contract_ref,contract_revision,command_digest,
  snapshot_ref,expected_brief_revision,operation_id,idempotency_key,payload_ref,source_bytes,source_sha256,
  confirmed_source_sha256,rights_source_sha256,buyer_subject,confirmation_ref,rights_statement_ref,rights_evidence_ref)
 VALUES (?,?,?::uuid,?::bigint,?,1,?,?::uuid,?::integer,?,?,?,pg_catalog.decode(?,'hex'),?,?,?,?,?,?,?)
SQL,
        [...SCOPED,$v,CONTRACT,hash('sha256','fixture command'),$snapshot,$v,'operation.'.$v,'idempotency.'.$v,
            'payload.'.$v,bin2hex($source),$sha,$sha,$sha,BUYER,$confirmation,'rights.statement.fixture',
            'rights.evidence.'.$v])===1);
}
function event(PDO $db,int $gen,?string $priorKind,?int $priorVersion,string $kind,int $v,
    string $actor,string $confirmation,string $decision): void {
    need(effect($db,<<<'SQL'
INSERT INTO portal_onepage_brief_candidate.brief_event
 (tenant_ref,order_ref,site_ref,generation,predecessor_generation,predecessor_kind,predecessor_source_version,
  kind,source_version,buyer_subject,actor_subject,confirmation_ref,decision_ref,authority_evidence_ref)
 VALUES (?,?,?::uuid,?::bigint,?::bigint,?,?::bigint,?,?::bigint,?,?,?,?,?)
SQL,
        [...SCOPED,$gen,$gen===1?null:$gen-1,$priorKind,$priorVersion,$kind,$v,BUYER,$actor,
            $confirmation,$decision,'fixture.evidence.'.$gen])===1);
}
function head(PDO $db,int $old,int $new): int {
    if ($old===0) {
        return effect($db,'INSERT INTO portal_onepage_brief_candidate.brief_head'
            .'(tenant_ref,order_ref,site_ref,generation) VALUES (?,?,?::uuid,?::bigint)',[...SCOPED,$new]);
    }
    return effect($db,'UPDATE portal_onepage_brief_candidate.brief_head SET generation=?::bigint'
        .' WHERE tenant_ref=? AND order_ref=? AND site_ref=?::uuid AND generation=?::bigint',
        [$new,...SCOPED,$old]);
}
function tx(PDO $db,Closure $body): void {
    need(!$db->inTransaction() && $db->beginTransaction());
    try {
        $body();
        need($db->commit());
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
function ro(PDO $db,Closure $body): mixed {
    $out=null;
    tx($db,static function () use ($db,$body,&$out): void {
        $db->exec('SET TRANSACTION READ ONLY');
        $out=$body();
    });
    return $out;
}
function state(PDO $db): array {
    // Full before/after rows of this empty-at-start single synthetic schema,
    // not mere row counts; run only through the independent RO observer.
    return ro($db,static function () use ($db): array {
        return [
            selectRows($db,'SELECT * FROM portal_onepage_brief_candidate.brief_head ORDER BY 1,2,3'),
            selectRows($db,'SELECT * FROM portal_onepage_brief_candidate.brief_event ORDER BY 1,2,3,4'),
            selectRows($db,<<<'SQL'
SELECT tenant_ref,order_ref,site_ref,source_version,contract_ref,contract_revision,command_digest,
       snapshot_ref,expected_brief_revision,operation_id,idempotency_key,payload_ref,
       pg_catalog.encode(source_bytes,'hex') AS source_hex,source_sha256,confirmed_source_sha256,
       rights_source_sha256,buyer_subject,confirmation_ref,rights_statement_ref,rights_evidence_ref,confirmed_at
 FROM portal_onepage_brief_candidate.confirmed_revision ORDER BY 1,2,3,4
SQL),
        ];
    });
}
function readCurrent(PDO $db,int $v,bool $withBody,array $scope=SCOPED): array {
    $columns=$withBody
        ?",pg_catalog.encode(c.source_bytes,'hex') AS source_hex"
        :',e.actor_subject AS reviewer_subject,e.authority_evidence_ref';
    // Fixed SQL from v2 Projection PHP, with a two-way internal switch only.
    $sql=<<<'SQL'
SELECT c.tenant_ref,c.order_ref,c.site_ref::text,c.contract_ref,c.contract_revision,
       c.snapshot_ref::text,c.source_version,c.expected_brief_revision,
       c.operation_id,c.idempotency_key,c.payload_ref,c.source_sha256,c.command_digest,c.buyer_subject,
       e.decision_ref AS approval_ref,e.generation AS approval_epoch
SQL
        .$columns.
    <<<'SQL'
 FROM portal_onepage_brief_candidate.brief_head h
 JOIN portal_onepage_brief_candidate.brief_event e USING (tenant_ref,order_ref,site_ref,generation)
 JOIN portal_onepage_brief_candidate.confirmed_revision c USING (tenant_ref,order_ref,site_ref,source_version)
 WHERE h.tenant_ref=?::text AND h.order_ref=?::text AND h.site_ref=?::uuid
   AND c.source_version=?::bigint AND e.kind='approve'
SQL;
    return ro($db,static fn()=>selectRows($db,$sql,[...$scope,$v]));
}
function checkCurrent(PDO $ro,int $version,string $snapshot,string $approval,int $gen): void {
    $a=readCurrent($ro,$version,true);
    $b=readCurrent($ro,$version,false);
    need(count($a)===1 && count($b)===1 && count($a[0])===17 && count($b[0])===18);
    $material=$a[0]; $witness=$b[0];
    need($material['source_hex']===bin2hex(bodyFor($version)) && $witness['reviewer_subject']===REVIEWER
        && $witness['authority_evidence_ref']==='fixture.evidence.'.$gen);
    unset($material['source_hex'],$witness['reviewer_subject'],$witness['authority_evidence_ref']);
    need($material===$witness && $material['tenant_ref']===ORG && $material['order_ref']===ORDER
        && $material['site_ref']===SITE && $material['contract_ref']===CONTRACT
        && (string)$material['contract_revision']==='1'
        && $material['snapshot_ref']===$snapshot && (string)$material['source_version']===(string)$version
        && (string)$material['expected_brief_revision']===(string)$version
        && $material['source_sha256']===hash('sha256',bodyFor($version))
        && $material['command_digest']===hash('sha256','fixture command') && $material['buyer_subject']===BUYER
        && $material['operation_id']==='operation.'.$version && $material['idempotency_key']==='idempotency.'.$version
        && $material['payload_ref']==='payload.'.$version && $material['approval_ref']===$approval
        && (string)$material['approval_epoch']===(string)$gen);
}
function deny(PDO $db,PDO $observer,string $state,Closure $body,bool $atCommit=false): void {
    $before=state($observer);
    $bodyFinished=false; $seen=null;
    try {
        tx($db,static function () use ($body,&$bodyFinished): void {
            $body();
            $bodyFinished=true;
        });
    } catch (PDOException $e) {
        $seen=$e->errorInfo[0]??$e->getCode();
    }
    need($seen===$state && $bodyFinished===$atCommit && state($observer)===$before);
}

$stage='INPUT';
ini_set('display_errors','0');
ini_set('log_errors','0');
try {
    need(PHP_SAPI==='cli' && PHP_INT_SIZE===8 && PHP_VERSION_ID>=80300 && PHP_VERSION_ID<80400
        && function_exists('posix_geteuid') && function_exists('sodium_crypto_sign_verify_detached')
        && in_array('pgsql',PDO::getAvailableDrivers(),true));
    $uid=posix_geteuid(); $gid=posix_getegid();
    $status=file_get_contents('/proc/self/status',false,null,0,65536);
    need($uid>0 && is_string($status) && preg_match('/^NoNewPrivs:\s*1$/m',$status)===1);
    foreach (['Eff','Prm','Bnd','Inh','Amb'] as $cap) need(preg_match('/^Cap'.$cap.':\s*0000000000000000$/m',$status)===1);
    need($argc===5 && $argv[3]!==$argv[4]);
    [,$run,$outerNs,$control,$envelope]=$argv;
    $sd=lstat($run);
    need(preg_match('~\A/tmp/sites-stage3-[A-Za-z0-9]{8,32}\z~D',$run)===1 && realpath($run)===$run
        && is_array($sd) && ($sd['mode']&0177777)===0040700 && $sd['uid']===$uid && $sd['gid']===$gid
        && preg_match('/\Anet:\[[1-9][0-9]*\]\z/D',$outerNs)===1);
    foreach (array_keys(getenv()) as $key) need(!str_starts_with($key,'PG') && !str_starts_with($key,'GITHUB_'));
    $receiver=dirname(__DIR__).'/sites-stage3/Stage3ReceiptChannel.php';
    bytes($receiver,RECEIVER_SHA,8192);
    require $receiver;
    $ddl=bytes(__DIR__.'/brief-persistence-v2.sql',DDL_SHA,32768);
    $stage='RECEIPT';
    $r=(new Stage3ReceiptChannel())->receive(readPipe($control),readPipe($envelope));
    $w=unpackWitness($r,$run,$outerNs);
    unset($r);
    $stage='IDENTITY';
    $pwPath=$run.'/admin.password'; $f=lstat($pwPath);
    need(is_array($f) && realpath($pwPath)===$pwPath && ($f['mode']&0177777)===0100600
        && $f['uid']===$uid && $f['gid']===$gid && $f['size']===65);
    $pw=file_get_contents($pwPath,false,null,0,66);
    need(is_string($pw) && preg_match('/\A[0-9a-f]{64}\n\z/D',$pw)===1);
    $pw=substr($pw,0,64);
    $db=connectedAdmin($pw,$w); $observer=connectedAdmin($pw,$w);
    sodium_memzero($pw);
    $primary=effectiveIdentity($db,$w); $secondary=effectiveIdentity($observer,$w);
    need($primary[0]!==$secondary[0]);
    foreach ([$db,$observer] as $connection) {
        $connection->exec('SET statement_timeout=3000; SET lock_timeout=1000; SET idle_in_transaction_session_timeout=10000');
    }
    $observer->exec('SET default_transaction_read_only=on');
    need(ro($db,static fn()=>one($db,'SELECT pg_catalog.to_regnamespace(?) IS NULL AS absent',[NS]))===['absent'=>true]);
    need(effectiveIdentity($db,$w)===$primary);
    marker('BRIEF_V2_ONE_EFFECTIVE_PDO_OK');
    $stage='EXACT_DDL';
    // DDL is exact, no interpolation; preserve its own BEGIN/COMMIT on the
    // same just-requalified PDO. Any exception ends the job, no implicit retry.
    need($db->exec($ddl)!==false);
    unset($ddl);
    need(!$db->inTransaction() && state($observer)===[[],[],[]]);
    $stage='CONFIRM_A';
    tx($db,static function () use ($db): void {
        revision($db,0,SNAP_A,'confirmation.a');
        event($db,1,null,null,'confirm',0,BUYER,'confirmation.a','confirmation.a');
        need(head($db,0,1)===1);
    });
    need(readCurrent($observer,0,true)===[] && readCurrent($observer,0,false)===[]);
    $stage='NEGATIVE_PENDING_A';
    deny($db,$observer,'23514',static fn()=>event($db,2,'confirm',0,'approve',0,BUYER,'confirmation.a','approve.self'));
    deny($db,$observer,'23503',static fn()=>event($db,2,'confirm',0,'approve',0,REVIEWER,'confirmation.other','approve.wrong.ref'));
    deny($db,$observer,'40001',static fn()=>event($db,2,'confirm',0,'approve',0,REVIEWER,'confirmation.a','approve.orphan'),true);
    $stage='APPROVE_A';
    tx($db,static function () use ($db): void {
        event($db,2,'confirm',0,'approve',0,REVIEWER,'confirmation.a','approval.a');
        need(head($db,1,2)===1);
    });
    checkCurrent($observer,0,SNAP_A,'approval.a',2);
    foreach ([['org.other',ORDER,SITE],[ORG,'order.other',SITE],[ORG,ORDER,SNAP_B]] as $other) {
        need(readCurrent($observer,0,true,$other)===[] && readCurrent($observer,0,false,$other)===[]);
    }
    need(readCurrent($observer,1,true)===[]);
    marker('BRIEF_V2_EXACT_DDL_AND_ROWS_OK');
    $stage='NEGATIVE_ROLLBACK';
    deny($db,$observer,'23514',static function () use ($db): void {
        revision($db,1,SNAP_B,'confirmation.b');
        event($db,3,'approve',0,'confirm',1,BUYER,'confirmation.b','confirmation.mismatch');
    });
    deny($db,$observer,'40001',static fn()=>revision($db,1,SNAP_B,'confirmation.b'),true);
    $before=state($observer);
    tx($db,static function () use ($db): void { need(head($db,1,3)===0); });
    need(state($observer)===$before);
    marker('BRIEF_V2_NEGATIVE_ROLLBACK_OK');
    $stage='REVOKE_AND_REPLACE';
    tx($db,static function () use ($db): void {
        event($db,3,'approve',0,'revoke',0,REVOKE_FIXTURE,'confirmation.a','revoke.a');
        need(head($db,2,3)===1);
    });
    need(readCurrent($observer,0,true)===[] && readCurrent($observer,0,false)===[]);
    deny($db,$observer,'23514',static fn()=>event($db,4,'revoke',0,'approve',0,REVIEWER,'confirmation.a','reapprove.a'));
    tx($db,static function () use ($db): void {
        revision($db,1,SNAP_B,'confirmation.b');
        event($db,4,'revoke',0,'confirm',1,BUYER,'confirmation.b','confirmation.b');
        need(head($db,3,4)===1);
    });
    need(readCurrent($observer,1,true)===[]);
    deny($db,$observer,'23503',static fn()=>event($db,5,'confirm',1,'approve',1,REVIEWER,'confirmation.a','approve.borrowed.ref'));
    tx($db,static function () use ($db): void {
        event($db,5,'confirm',1,'approve',1,REVIEWER,'confirmation.b','approval.b');
        need(head($db,4,5)===1);
    });
    checkCurrent($observer,1,SNAP_B,'approval.b',5);
    need(readCurrent($observer,0,true)===[] && readCurrent($observer,0,false)===[]);
    $after=state($observer);
    need(count($after[0])===1 && count($after[1])===5 && count($after[2])===2
        && (string)$after[0][0]['generation']==='5'
        && $after[1][1]['kind']==='approve' && $after[1][1]['decision_ref']==='approval.a'
        && $after[1][2]['kind']==='revoke' && $after[1][2]['decision_ref']==='revoke.a'
        && $after[2][0]['source_hex']===bin2hex(BODY_A) && $after[2][1]['source_hex']===bin2hex(BODY_B)
        && $after[2][0]['source_hex']!==$after[2][1]['source_hex']
        && $after[2][0]['source_sha256']===hash('sha256',BODY_A)
        && $after[2][1]['source_sha256']===hash('sha256',BODY_B)
        && $after[2][0]['source_sha256']!==$after[2][1]['source_sha256']);
    deny($db,$observer,'55000',static fn()=>$db->exec('DELETE FROM portal_onepage_brief_candidate.confirmed_revision'));
    deny($db,$observer,'55000',static fn()=>$db->exec('TRUNCATE portal_onepage_brief_candidate.brief_event, portal_onepage_brief_candidate.brief_head'));
    deny($db,$observer,'40001',static fn()=>head($db,5,4));
    need(effectiveIdentity($db,$w)===$primary && effectiveIdentity($observer,$w)===$secondary);
    marker('BRIEF_V2_REVOKED_HISTORICAL_NOT_CURRENT_OK');
    marker('BRIEF_V2_DISTINCT_BRIEF_SOURCE_AND_STALE_VERSION_DENIED_OK');
} catch (Throwable) {
    fwrite(STDERR,'BRIEF_V2_VALIDATOR_REFUSED '.$stage."\n");
    exit(1);
}
