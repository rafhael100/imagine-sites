<?php
declare(strict_types=1);
// Pure comparison shared by the live preflight and offline refusal tests.
function recoveryIdentity(array $expected, array $actual): void {
    foreach (['db','login','actor','address','port','directory','cluster'] as $key) {
        if (!isset($expected[$key], $actual[$key]) || (string)$expected[$key] !== (string)$actual[$key]) {
            throw new RuntimeException('Recovery SQL identity mismatch');
        }
    }
    if ((int)($actual['version'] ?? 0) < 180000 || (int)$actual['version'] >= 190000) {
        throw new RuntimeException('Recovery requires PG18');
    }
}
function recoveryPair(array $source, array $destination): void {
    foreach (['cluster','directory','login','port'] as $key) {
        if (empty($source[$key]) || empty($destination[$key]) || (string)$source[$key] === (string)$destination[$key]) {
            throw new RuntimeException('Recovery requires independent disposable identities');
        }
    }
}
function recoveryPrivatePath(string $path,bool $directory):void {
    $s=lstat($path);
    if(!$s||is_link($path)||realpath($path)!==$path||$s['uid']!==posix_geteuid()||($s['mode']&0777)!==($directory?0700:0600)||($directory?!is_dir($path):(!is_file($path)||$s['nlink']!==1)))throw new RuntimeException('Private path mismatch');
}
function recoveryExpectedRefusal(callable $operation,string $expected):void {
    try{$operation();}catch(RuntimeException $e){
        if(get_class($e)!==RuntimeException::class||$e->getMessage()!==$expected)throw $e;
        return;
    }
    throw new LogicException('Expected refusal absent');
}
function recoveryRuntimeIdentity(array $expected,array $actual):void {
    foreach(['db','login','actor','address','port'] as $key)if(!isset($actual[$key])||(string)$expected[$key]!== (string)$actual[$key])throw new RuntimeException('Runtime destination mismatch');
    if((int)$actual['version']<180000||(int)$actual['version']>=190000)throw new RuntimeException('Runtime version mismatch');
}
function recoveryCanonicalDdl(string $sql):string {
    // Only an exact, paired psql meta-command key is variable; preserve every SQL byte.
    preg_match_all('/^\\\\restrict ([A-Za-z0-9]+)$/m',$sql,$open);
    preg_match_all('/^\\\\unrestrict ([A-Za-z0-9]+)$/m',$sql,$close);
    if(count($open[1])!==1||$open[1]!==$close[1])throw new RuntimeException('Unexpected dump framing');
    return preg_replace_callback('/^\\\\(un)?restrict '.preg_quote($open[1][0],'/').'$/m',static fn(array $m):string=>'\\'.($m[1]??'').'restrict LAB_KEY',$sql);
}

function recoveryCleanEnvironment(array $allowed):void {
    foreach(array_keys(getenv()) as $key)if(!in_array($key,$allowed,true))putenv($key);
    $_ENV=[];$_SERVER=[];
}
function recoveryCanonicalAclRows(array $rows):array {
    $keys=['kind','name','owner','grantor','grantee','privilege','is_grantable'];
    foreach($rows as $row){
        if(array_keys($row)!==$keys||!is_string($row['kind'])||!is_string($row['name'])||!is_string($row['owner']))throw new RuntimeException('Malformed ACL tuple');
        foreach(['grantor','grantee','privilege'] as $key)if($row[$key]!==null&&!is_string($row[$key]))throw new RuntimeException('Malformed ACL tuple');
        if($row['is_grantable']!==null&&!is_bool($row['is_grantable']))throw new RuntimeException('Malformed ACL tuple');
    }
    // Order-independent multiset: do not drop duplicates, fields, PUBLIC or grant options.
    usort($rows,static fn(array $a,array $b):int=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
    return $rows;
}
