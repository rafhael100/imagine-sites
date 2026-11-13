<?php

declare(strict_types=1);

/**
 * F1-109: drift sentinel for the two public candidate contract shapes.
 * No vendor schema validator, network, live Portal, credentials or data.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Integration\\';
    if (!str_starts_with($class,$prefix)) return;
    $short=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
        throw new RuntimeException('Unsafe candidate class name');
    }
    require __DIR__.'/../src/Integration/'.$short.'.php';
});

use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Integration\CurrentPortalProjectAuthorizationSnapshot as Snapshot;

$assertions=0;
function f109Check(bool $ok,string $description):void {
    global $assertions;
    if (!$ok) throw new RuntimeException('FAIL F1-109 '.$description);
    ++$assertions;
    echo "PASS F1-109 ".$description."\n";
}
function f109Invalid(callable $callback):bool {
    try { $callback(); }
    catch (InvalidArgumentException) { return true; }
    return false;
}
function f109Json(string $path):array {
    $raw=file_get_contents($path);
    if (!is_string($raw)) throw new RuntimeException('Missing candidate contract');
    $parsed=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if (!is_array($parsed)) throw new RuntimeException('Invalid candidate contract');
    return $parsed;
}

$base=__DIR__.'/../contracts/portal-sites/v1';
$auth=f109Json($base.'/project-authorization-snapshot.candidate.schema.json');
$create=f109Json($base.'/create-project.schema.json');
$expected=Snapshot::WIRE_FIELDS;
$required=$auth['required']??[];
$propertyNames=array_keys($auth['properties']??[]);
sort($expected);
sort($required);
sort($propertyNames);
f109Check($auth['type']==='object' && $auth['additionalProperties']===false &&
    $required===$expected && $propertyNames===$expected,
    'authorization JSON schema required/properties match PHP decoder wire fields');
f109Check(($auth['properties']['schemaVersion']['const']??null)===Snapshot::WIRE_SCHEMA_VERSION &&
    ($auth['properties']['boundCommand']['$ref']??null)==='./create-project.schema.json',
    'schema version and nested command reference match the strict PHP parser');
f109Check(($auth['properties']['authorizationRevision']['type']??null)==='integer' &&
    ($auth['properties']['authorizationRevision']['minimum']??null)===1 &&
    ($auth['properties']['checkedAt']['type']??null)==='integer' &&
    ($auth['properties']['validUntil']['type']??null)==='integer',
    'authorization revision and timestamps remain strictly integer in schema');
$allowed=['eligible','revoked','suspended'];
$schemaStatuses=$auth['properties']['status']['enum']??[];
sort($allowed);
sort($schemaStatuses);
f109Check($allowed===$schemaStatuses,
    'JSON schema lists exactly the statuses enforced by PHP constructor');

$commandFields=[
    'schemaVersion'=>'1','operationId'=>'operation-f109',
    'tenantRef'=>'tenant-f109','orderRef'=>'order-f109',
    'contractRef'=>'contract-f109','contractRevision'=>2,
    'productCode'=>'onepage','idempotencyKey'=>'idem-f109',
];
$commandExpected=array_keys($commandFields);
$cmdRequired=$create['required']??[];
$cmdProperties=array_keys($create['properties']??[]);
sort($commandExpected);
sort($cmdRequired);
sort($cmdProperties);
f109Check(($create['additionalProperties']??null)===false &&
    $cmdRequired===$commandExpected && $cmdProperties===$commandExpected,
    'referenced CreateProject JSON schema remains identical to runtime command fields');

$command=SiteProjectCommand::fromArray($commandFields);
$now=1790000000;
$wire=[
    'schemaVersion'=>Snapshot::WIRE_SCHEMA_VERSION,
    'boundCommand'=>$commandFields,
    'authorizationRevision'=>7,
    'status'=>'eligible',
    'checkedAt'=>$now,
    'validUntil'=>$now+30,
];
$raw=json_encode($wire,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$parsed=Snapshot::fromCanonicalJson($raw);
f109Check($parsed->isCurrentFor($command,$now) &&
    !$parsed->isCurrentFor($command,$now+30),
    'maximum 30-second candidate TTL is allowed but expiry is exclusive');
f109Check(f109Invalid(fn()=> Snapshot::fromArray(array_replace($wire,[
    'validUntil'=>$now+31,
]))), 'TTL longer than 30 seconds is denied by runtime parser');

foreach (['eligible','suspended','revoked'] as $status) {
    $candidate=array_replace($wire,['status'=>$status]);
    $snapshot=Snapshot::fromArray($candidate);
    f109Check($snapshot->status===$status &&
        $snapshot->isCurrentFor($command,$now)===($status==='eligible'),
        'status '.$status.' is decoded and only eligible is authorized');
}
$bad=array_replace($wire,['status'=>'approved']);
f109Check(f109Invalid(fn()=>Snapshot::fromArray($bad)),
    'unlisted approval-like status is denied');
$bad=$wire;
$bad['extraPortalApproval']=true;
f109Check(f109Invalid(fn()=>Snapshot::fromArray($bad)),
    'runtime rejects unknown schema properties instead of inferring approval');

echo "\n$assertions F1-109 contract drift assertions passed; no Portal IAM or deployment.\n";
