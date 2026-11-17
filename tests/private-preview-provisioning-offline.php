<?php
declare(strict_types=1);
require __DIR__.'/../src/Production/PrivatePreviewProvisioning.php';
use Imagine\Sites\Production\PrivatePreviewProvisioning;

// Manifest validation only: no Laravel boot, connection, role, credential or DDL.
$now=1800000000;
$manifest=['schema'=>'SITES_PREVIEW_PROVISION_V1','contract_sha256'=>PrivatePreviewProvisioning::contractSha(),
    'target'=>['database'=>'sites_staging','bootstrap_login'=>'sites_bootstrap','host'=>'db','port'=>5432,
        'server_address'=>'127.0.0.1','database_oid'=>12345,'system_identifier'=>'1234567890123456789'],
    'principals'=>[
        ['login'=>'sites_preview_author','company_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','profile'=>'author','expires_at'=>$now+3600],
        ['login'=>'sites_preview_reviewer','company_id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','profile'=>'reviewer','expires_at'=>$now+3600],
    ]];
$checks=0;
$ok=static function(bool $value,string $label)use(&$checks):void {if(!$value)throw new RuntimeException($label);$checks++;};
$validated=PrivatePreviewProvisioning::validateManifest($manifest,$now);
$ok($validated===$manifest,'complete manifest valid');
$reversed=$manifest;$reversed['principals']=array_reverse($reversed['principals']);
$ok(PrivatePreviewProvisioning::validateManifest($reversed,$now)===$manifest,'principal order canonical');
$mutations=[
    fn(&$m)=>$m['schema']='OTHER',
    fn(&$m)=>$m['contract_sha256']=str_repeat('0',64),
    fn(&$m)=>$m['password']='not-allowed',
    fn(&$m)=>$m['target']['bootstrap_password']='not-allowed',
    fn(&$m)=>$m['target']['database']='sites_staging; DROP SCHEMA sites_core',
    fn(&$m)=>$m['target']['host']='db;sslmode=disable',
    fn(&$m)=>$m['target']['port']='5432',
    fn(&$m)=>$m['target']['port']=0,
    fn(&$m)=>$m['target']['port']=65536,
    fn(&$m)=>$m['target']['database_oid']=0,
    fn(&$m)=>$m['target']['system_identifier']='0',
    fn(&$m)=>$m['target']['server_address']='db.example.invalid',
    fn(&$m)=>$m['principals']=[],
    fn(&$m)=>$m['principals']=array_fill(0,33,$m['principals'][0]),
    fn(&$m)=>$m['principals'][1]=$m['principals'][0],
    fn(&$m)=>$m['principals'][0]['login']='sites_preview_owner',
    fn(&$m)=>$m['principals'][0]['login']='postgres',
    fn(&$m)=>$m['principals'][0]['login']='sites_preview_user";GRANT ALL',
    fn(&$m)=>$m['principals'][0]['company_id']='aaaaaaaa-aaaa-1aaa-8aaa-aaaaaaaaaaaa',
    fn(&$m)=>$m['principals'][0]['profile']='owner',
    fn(&$m)=>$m['principals'][0]['expires_at']=$now,
    fn(&$m)=>$m['principals'][0]['expires_at']=(string)($now+3600),
    fn(&$m)=>$m['principals'][0]['expires_at']=253402300800,
    fn(&$m)=>$m['principals'][0]['sql_password']='not-allowed',
];
foreach($mutations as $i=>$mutate) {
    $candidate=$manifest;$mutate($candidate);$rejected=false;
    try{PrivatePreviewProvisioning::validateManifest($candidate,$now);}
    catch(RuntimeException $e){$rejected=str_starts_with($e->getMessage(),'PROVISION_DENIED:');}
    $ok($rejected,'unsafe manifest refused '.$i);
}
echo "PROVISIONING OFFLINE PASS: $checks manifest cases; no database, account or schema operation.\n";
