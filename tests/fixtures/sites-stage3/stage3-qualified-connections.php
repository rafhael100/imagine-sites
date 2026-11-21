<?php
declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\PostgresConnection;
use SitesStage3Fixture\Stage3ReceiptChannel;
use SitesStage3Fixture\Stage3SupervisedSlots;

function need(bool $ok): void { if (!$ok) throw new RuntimeException(); }
/** The inherited kernel file description must actually be a read-only root FIFO. */
function readPipe(string $fd) {
    need(preg_match('/\A(?:[3-9]|[1-9][0-9]{1,5})\z/D',$fd) === 1);
    $target=readlink('/proc/self/fd/'.$fd);
    need(is_string($target) && preg_match('/\Apipe:\[([1-9][0-9]*)\]\z/D',$target,$inode) === 1);
    $info=file_get_contents('/proc/self/fdinfo/'.$fd,false,null,0,1024);
    need(is_string($info) && preg_match('/^flags:\s*([0-7]{1,12})$/m',$info,$flags) === 1
        && (octdec($flags[1]) & 3) === 0);
    $pipe=fopen('php://fd/'.$fd,'rb');
    need(is_resource($pipe));
    $s=fstat($pipe);
    need(is_array($s) && ($s['mode']&0170000)===0010000 && $s['uid']===0 && (string)$s['ino']===$inode[1]);
    return $pipe;
}
$stage='RECEIPT';
ini_set('display_errors','0');
ini_set('log_errors','0');
try {
    need($argc===6 && $argv[4]!==$argv[5]);
    [,$portal,$run,$port,$control,$envelope]=$argv;
    need(preg_match('~\A/tmp/sites-stage3-[A-Za-z0-9]{8,32}\z~D',$run)===1
        && realpath($portal)===$portal && realpath($run)===$run && $port==='55483');
    require __DIR__.'/Stage3ReceiptChannel.php';
    require __DIR__.'/Stage3SupervisedSlots.php';
    $receipt=(new Stage3ReceiptChannel())->receive(readPipe($control),readPipe($envelope));
    // PHP closes its stream copies before DB; inherited read-only originals
    // can remain until this fixed process exits (no subprocesses are started).
    $stage='NATIVE_FACTORY';
    require $portal.'/apps/portal/vendor/autoload.php';
    need(PostgresConnection::getResolver('pgsql')===null);
    $passwordPath=$run.'/admin.password';
    $pw=lstat($passwordPath);
    $uid=posix_geteuid();
    need(is_array($pw) && $uid!==0 && $pw['uid']===$uid && ($pw['mode']&0177777)===0100600
        && $pw['size']===65);
    $password=file_get_contents($passwordPath,false,null,0,66);
    need(is_string($password) && preg_match('/\A[0-9a-f]{64}\n\z/D',$password)===1);
    $factory=new ConnectionFactory(new Container());
    $names=['portal_native_orders_ci'=>'stage3_native_admin',
        'portal_asaas_ci'=>'stage3_inbox_admin','portal_sites_pair_ci'=>'stage3_sites_admin'];
    $connections=[];
    foreach ($names as $db=>$alias) {
        $connections[$db]=$factory->make([
            'driver'=>'pgsql','database'=>$db,'username'=>'portal_native_test_bootstrap','host'=>'127.0.0.1',
            'port'=>(int)$port,'sslmode'=>'disable','charset'=>'utf8','prefix'=>'','search_path'=>'public',
            'password'=>substr($password,0,64),
            'options'=>[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::ATTR_PERSISTENT=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>3],
        ],$alias);
    }
    unset($password);
    $stage='QUALIFIED_SLOTS';
    $guard=Stage3SupervisedSlots::qualify($receipt['json'],$receipt['signature'],
        $receipt['trustedRunnerKey'],$receipt['trustedNonce'],$connections);
    unset($receipt);
    foreach ($connections as $db=>$connection) {
        // Keep the final native SQL identity recheck. No DDL, write or schema
        // transaction follows in this proof.
        $pdo=$guard->beforeDdl($db,$connection);
        need($pdo instanceof PDO);
    }
    fwrite(STDOUT,"STAGE3_PHP_THREE_QUALIFIED_CONNECTIONS_OK\n");
} catch (Throwable) {
    fwrite(STDERR,"STAGE3_SLOTS_DRIVER_REFUSED ".$stage."\n");
    exit(1);
}
