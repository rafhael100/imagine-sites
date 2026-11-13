<?php

declare(strict_types=1);

/**
 * F1-365: one short-lived isolated PHP CLI worker per localhost HTTP
 * request. Delegates to unchanged F1-364 CI-only atomic PG adapter.
 * No Laravel HTTP routes are registered by this fixture.
 */
function f365WorkerReply(string $kind):never
{
    echo json_encode(['kind'=>$kind],JSON_THROW_ON_ERROR)."\n";
    exit(0);
}
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F365_HTTP_LAB')!=='1' ||
    getenv('SITES_F360_PG_LAB')!=='1' ||
    getenv('SITES_F363_NONCE_LAB')!=='1' ||
    getenv('SITES_F364_BRIDGE_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_PORT')!=='5432' ||
    getenv('DB_DATABASE')!=='sites_f360_lab') {
    f365WorkerReply('denied');
}
$root=dirname(__DIR__,2);
$sitesDir=(string)getenv('SITES_F362_SITES_PATH');
if(!is_file($sitesDir.'/src/Security/SyntheticSignedAuthorityAdmission.php') ||
    !is_file($root.'/src/Persistence/LabTransactionalSignedIngressV1.php')) {
    f365WorkerReply('error');
}
spl_autoload_register(static function(string $class) use($root,$sitesDir):void {
    foreach([
        'Imagine\\Sites\\Persistence\\'=>$root.'/src/Persistence/',
        'Imagine\\Sites\\Integration\\'=>$sitesDir.'/src/Integration/',
        'Imagine\\Sites\\Security\\'=>$sitesDir.'/src/Security/',
    ] as $prefix=>$directory) {
        if(!str_starts_with($class,$prefix))continue;
        $short=substr($class,strlen($prefix));
        if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Unrecognized fixture class.');
        }
        require $directory.$short.'.php';
        return;
    }
});
$input=stream_get_contents(STDIN,8193);
if(!is_string($input) || strlen($input)<2 || strlen($input)>8192) {
    f365WorkerReply('invalid');
}
try{
    $body=json_decode($input,true,16,JSON_THROW_ON_ERROR);
}catch(JsonException) {
    f365WorkerReply('invalid');
}
if(!is_array($body) ||
    array_keys($body)!==['signedCommand','portalProof','externalProof','siteRef'] ||
    !is_array($body['signedCommand']) ||
    !is_array($body['portalProof']) ||
    !is_array($body['externalProof']) ||
    !is_string($body['siteRef']) ||
    !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$body['siteRef'])) {
    f365WorkerReply('invalid');
}
try {
    $db=new PDO(
      'pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5',
      'sites_f360_b',
      'f360-fictional-tenant-b-only',
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,
       PDO::ATTR_TIMEOUT=>6]
    );
    $ingress=new \Imagine\Sites\Persistence\LabTransactionalSignedIngressV1($db);
    $result=$ingress->admit(
        $body['signedCommand'],$body['portalProof'],$body['externalProof'],
        'F365-FICTIONAL-PORTAL-COMMAND-KEY-00001',
        'F365-FICTIONAL-PORTAL-AUTHORITY-KEY-0002',
        'F365-FICTIONAL-EXTERNAL-REVOCATION-KEY-03',
        $body['siteRef'],time()
    );
    f365WorkerReply($result['status']);
} catch (PDOException $e) {
    f365WorkerReply($e->getCode()==='42501'?'denied':'error');
} catch (DomainException|InvalidArgumentException $e) {
    f365WorkerReply('denied');
} catch (Throwable $e) {
    f365WorkerReply('error');
}
