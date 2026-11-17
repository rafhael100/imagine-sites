<?php
declare(strict_types=1);

// Execute the actual CLI against the parent's verified disposable TLS database.
// No CLI stubs, replacement PDO source or filesystem permission bypass is used.
if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('SITES_RELEASE_PACKAGE_CI')!=='1'||
    getenv('SITES_RELEASE_REGISTRY_CI')!=='1'||!isset($admin,$writers,$sources,$snapshot,$ok,$checks,
        $packageSite,$packageCommand,$packageManifest,$packageArtifact,$caFile)) {
    throw new RuntimeException('Package CLI requires verified registry fixture');
}
$cliChecksBefore=$checks;
$cliManifestPath='/run/secrets/release_preparation';
$cliHtmlPath='/run/sites-release-input/index.html';
$cliOutput='/var/lib/imagine-sites/release-candidates';
foreach([$cliManifestPath,'/run/secrets/sites_release_reader','/run/secrets/sites_release_ca'] as $fixed) {
    if(!is_file($fixed)||is_link($fixed)||realpath($fixed)!==$fixed)throw new RuntimeException('Reserved CLI custody path required');
}
if(file_exists($cliHtmlPath)||is_link($cliHtmlPath)||!is_dir($cliOutput)||is_link($cliOutput)||
    (fileperms($cliOutput)&0777)!==0700||fileowner($cliOutput)!==posix_geteuid())throw new RuntimeException('Fresh CLI output custody required');
$oldCliMask=umask(0077);
try {
    $htmlStream=fopen($cliHtmlPath,'x+b');
    if($htmlStream===false||fwrite($htmlStream,$packageArtifact->html)!==strlen($packageArtifact->html))throw new RuntimeException('CLI HTML fixture write failed');
    fclose($htmlStream);
} finally {umask($oldCliMask);}

// The preceding package test committed revocation revision2. Rebind explicitly
// through the genuine restricted writer before preparing the positive package.
$cliBinding=$writers['a']['binding_writer']->bind($packageSite,$packageManifest->releaseRef,3,2);
$ok($cliBinding->bindingRevision===3,'CLI positive requires a fresh explicit registry binding after revocation');
$cliInput=[
    'schemaVersion'=>'sites.release-preparation.input.v1',
    'target'=>[
        'host'=>'localhost','port'=>55449,'database'=>'postgres',
        'databaseOid'=>(int)$admin->query("SELECT oid FROM pg_database WHERE datname=current_database()")->fetchColumn(),
        'login'=>'rr_a_reader','serverAddress'=>'127.0.0.1','caSha256'=>hash_file('sha256','/run/secrets/sites_release_ca'),
    ],
    'command'=>(array)$packageCommand,
    'release'=>['siteRef'=>$packageSite,'releaseRef'=>$packageManifest->releaseRef,
        'artifactDigest'=>$packageArtifact->digest,'editorialRevision'=>3],
    'packageRef'=>'ci-release-package',
];
$writeCliManifest=static function(array $input)use($cliManifestPath):void {
    if(is_link($cliManifestPath)||!is_file($cliManifestPath))throw new RuntimeException('CLI manifest fixture was replaced');
    $json=json_encode($input,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    if(file_put_contents($cliManifestPath,$json)!==strlen($json))throw new RuntimeException('CLI manifest fixture write failed');
    chmod($cliManifestPath,0600);
};
$runCli=static function():array {
    $php=getenv('PHP_BIN')?:PHP_BINARY;
    $repo=dirname(__DIR__,3);
    $process=proc_open([$php,$repo.'/ops/release-candidate/prepare.php','prepare'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$repo);
    if(!is_resource($process))throw new RuntimeException('Package CLI process unavailable');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
};
$denyCli=static function(array $input,string $label)use($writeCliManifest,$runCli,$ok,$cliOutput):void {
    $writeCliManifest($input);$result=$runCli();
    $ok($result===['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"],$label.' actual CLI refusal without private diagnostics');
    if(!str_contains($input['packageRef'],'..')&&$input['packageRef']!=='ci-release-package'&&$input['packageRef']!=='ci-existing'&&$input['packageRef']!=='ci-symlink') {
        $ok(!file_exists($cliOutput.'/'.$input['packageRef'])&&!is_link($cliOutput.'/'.$input['packageRef']),$label.' no partial output');
    }
};
$cliBefore=$snapshot();
$writeCliManifest($cliInput);$cliResult=$runCli();
$ok($cliResult['exit']===0&&$cliResult['stderr']==='','actual preparation CLI succeeds over verified TLS');
$cliReceipt=json_decode($cliResult['stdout'],true,16,JSON_THROW_ON_ERROR);
$ok(($cliReceipt['schemaVersion']??null)==='sites.release-preparation.result.v1'&&
    ($cliReceipt['status']??null)==='prepared_for_internal_review'&&
    ($cliReceipt['bindingRevision']??null)===3&&($cliReceipt['editorialRevision']??null)===3&&
    ($cliReceipt['artifactDigest']??null)===$packageArtifact->digest&&
    ($cliReceipt['canPublish']??null)===false&&($cliReceipt['canAcceptRealOrders']??null)===false,
    'CLI receipt preserves exact current association and denies operational release');
$cliDirectory=$cliOutput.'/'.$cliInput['packageRef'];
$cliNames=array_values(array_diff(scandir($cliDirectory),['.','..']));sort($cliNames);
$ok($cliNames===['index.html','readiness.json','release-manifest.json']&&
    (fileperms($cliDirectory)&0777)===0700&&fileowner($cliDirectory)===posix_geteuid(),
    'atomic writer exposes exactly three files in private owned directory');
$cliBytes=[];$cliStats=[];
foreach(['index.html','release-manifest.json','readiness.json'] as $name) {
    $path=$cliDirectory.'/'.$name;$cliBytes[$name]=file_get_contents($path);$cliStats[$name]=lstat($path);
    $ok(!is_link($path)&&($cliStats[$name]['mode']&0777)===0600&&
        $cliStats[$name]['uid']===posix_geteuid()&&$cliStats[$name]['nlink']===1,'private single-link output '.$name);
}
$cliMetadata=json_decode($cliBytes['release-manifest.json'],true,16,JSON_THROW_ON_ERROR);
$cliReadiness=json_decode($cliBytes['readiness.json'],true,16,JSON_THROW_ON_ERROR);
$ok($cliBytes['index.html']===$packageArtifact->html&&$cliMetadata['bindingRevision']===3&&
    $cliMetadata['artifactDigest']===hash('sha256',$cliBytes['index.html'])&&
    $cliReceipt['packageDigest']===hash('sha256',implode("\0",$cliBytes))&&
    $cliReadiness['canPublish']===false&&$cliMetadata['officialClientAcceptance']===false,
    'delivered private bytes and package digest match actual persisted artifact');
$ok($snapshot()===$cliBefore,'successful CLI performs no registry/editorial/artifact writes');

$denyCli($cliInput,'existing output is never overwritten');
foreach($cliBytes as $name=>$bytes) {
    clearstatcache(true,$cliDirectory.'/'.$name);
    $after=lstat($cliDirectory.'/'.$name);
    $ok(file_get_contents($cliDirectory.'/'.$name)===$bytes&&$after['ino']===$cliStats[$name]['ino'],
        'existing package bytes and inode preserved '.$name);
}
$wrongOid=$cliInput;$wrongOid['packageRef']='ci-wrong-database';$wrongOid['target']['databaseOid']++;
$denyCli($wrongOid,'actual database identity mismatch');
$wrongCa=$cliInput;$wrongCa['packageRef']='ci-wrong-ca';$wrongCa['target']['caSha256']=str_repeat('0',64);
$denyCli($wrongCa,'CA fingerprint mismatch');
$wrongOrder=$cliInput;$wrongOrder['packageRef']='ci-other-order';$wrongOrder['command']['orderRef']='order-package-other';
$denyCli($wrongOrder,'same-tenant different order');
$changed=$cliInput;$changed['packageRef']='ci-changed-bytes';
file_put_contents($cliHtmlPath,$packageArtifact->html."\n");
try{$denyCli($changed,'tampered HTML');}finally{file_put_contents($cliHtmlPath,$packageArtifact->html);}

$badMode=$cliInput;$badMode['packageRef']='ci-unsafe-input-mode';$writeCliManifest($badMode);chmod($cliManifestPath,0644);
try{$modeResult=$runCli();$ok($modeResult===['exit'=>1,'stdout'=>'','stderr'=>"RELEASE_PREPARATION_DENIED\n"],
    'world-readable manifest refused by real CLI');}finally{chmod($cliManifestPath,0600);}
$ok(!file_exists($cliOutput.'/'.$badMode['packageRef']),'unsafe manifest mode produces no output');
$sentinel=$cliOutput.'/ci-existing';mkdir($sentinel,0700);file_put_contents($sentinel.'/sentinel','preserve existing directory');
$existing=$cliInput;$existing['packageRef']='ci-existing';$denyCli($existing,'pre-existing non-package directory');
$ok(file_get_contents($sentinel.'/sentinel')==='preserve existing directory'&&count(array_diff(scandir($sentinel),['.','..']))===1,
    'foreign contents of existing destination preserved');
symlink($cliDirectory,$cliOutput.'/ci-symlink');
$linked=$cliInput;$linked['packageRef']='ci-symlink';$denyCli($linked,'symlink destination');
$ok(is_link($cliOutput.'/ci-symlink')&&file_get_contents($cliDirectory.'/index.html')===$packageArtifact->html,
    'symlink target remains untouched');
$traversal=$cliInput;$traversal['packageRef']='../escape';$denyCli($traversal,'path traversal');
$linkedInput=$cliInput;$linkedInput['packageRef']='ci-linked-input';
unlink($cliHtmlPath);symlink($cliDirectory.'/index.html',$cliHtmlPath);
try{$denyCli($linkedInput,'symlink source');}finally{
    unlink($cliHtmlPath);$savedMask=umask(0077);file_put_contents($cliHtmlPath,$packageArtifact->html);umask($savedMask);
}
$ok($snapshot()===$cliBefore,'rejected CLI inputs preserve all SQL history');
$cliRevoked=$writers['a']['revoker']->revoke($packageSite,3);
$ok($cliRevoked===4,'explicit revocation precedes final CLI denial');
$cliRevokedBefore=$snapshot();$revokedInput=$cliInput;$revokedInput['packageRef']='ci-revoked-binding';
$denyCli($revokedInput,'current binding revoked');
$ok($snapshot()===$cliRevokedBefore&&file_get_contents($cliDirectory.'/index.html')===$packageArtifact->html,
    'revoked attempt changes neither history nor previously prepared package');
$ok(glob($cliOutput.'/.preparing-*')===[],'no incomplete staging directories remain after successful and rejected operations');
echo 'TECHNICAL PACKAGE CLI PASS: '.($checks-$cliChecksBefore).
    " assertions; actual CLI, PostgreSQL verify-full, restricted reader, atomic private files and real binding revocation; no publication\n";
