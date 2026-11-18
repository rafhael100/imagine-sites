<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Imagine\Sites\Production\{PrivatePreviewProvisioning,PrivatePreviewWorkspace,SiteBrief};

$upgradeStage='legacy_source_and_disposable_identity';$upgradeLoadLegacy=true;
require __DIR__.'/bootstrap.php';
if ($db->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()!==null ||
    $db->query("SELECT to_regclass('public.migrations')")->fetchColumn()!==null ||
    $db->query("SELECT to_regclass('public.provision_upgrade_sentinel')")->fetchColumn()!==null ||
    (int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname LIKE 'sites_preview_%'")->fetchColumn()!==0 ||
    file_exists($upgradeDirectory.'/state.json')) upgrade_fixture_deny('fresh_target_required');
$ok((new ReflectionClass(PrivatePreviewProvisioning::class))->getFileName()===
    $upgradeLegacyRoot.'/src/Production/PrivatePreviewProvisioning.php' &&
    !method_exists(PrivatePreviewProvisioning::class,'planUpgrade'),'actual_pinned_v1_class_loaded');
$ok((new ReflectionClass(PrivatePreviewWorkspace::class))->getFileName()===
    $upgradeLegacyRoot.'/src/Production/PrivatePreviewWorkspace.php','actual_pinned_v1_writer_loaded');
if (getenv('PREVIEW_UPGRADE_PREFLIGHT_ONLY')==='1') {
    echo "PREVIEW UPGRADE PREFLIGHT PASS: pinned V1 source and fresh disposable PostgreSQL18 TLS identity verified; no writes\n";exit(0);
}
$upgradeStage='legacy_existing_logins';
$legacyManifest=['schema'=>'SITES_PREVIEW_PROVISION_V1','contract_sha256'=>PrivatePreviewProvisioning::contractSha(),
    'target'=>$upgradeTarget,'principals'=>[]];
foreach (['author','editor','other'] as $name) {
    $p=$material[$name];$role='"'.$p['sql_login'].'"';
    $db->exec('CREATE ROLE '.$role.' LOGIN PASSWORD '.$db->quote($p['sql_password']).
        ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION');
    $legacyManifest['principals'][]=['login'=>$p['sql_login'],'company_id'=>$p['company_id'],
        'profile'=>$name==='editor'?'reviewer':'author','expires_at'=>$p['expires_at']];
}
$repository=new DatabaseMigrationRepository(app('db'),'migrations');$repository->setSource('pgsql');$repository->createRepository();
$db->exec("CREATE TABLE public.provision_upgrade_sentinel(id integer PRIMARY KEY,value text NOT NULL)");
$db->exec("INSERT INTO public.provision_upgrade_sentinel VALUES(1,'Preserve unrelated synthetic state')");
$upgradeStage='actual_legacy_provisioner';
$legacyService=new PrivatePreviewProvisioning($connection);
$legacyPlan=$legacyService->plan($legacyManifest);
$ok($legacyPlan['status']==='ready' && $db->query("SELECT to_regnamespace('sites_preview')")->fetchColumn()===null,
    'legacy_plan_readonly');
$result=$legacyService->apply($legacyManifest,$legacyPlan['plan_sha256'],true);
$ok($result['status']==='applied' && $result['changed']===true &&
    $legacyService->check($legacyManifest)['status']==='verified','actual_legacy_apply_and_check');
$base=$db->query("SELECT id,migration,batch FROM public.migrations WHERE migration='2026_11_16_000004_add_private_preview_workspace'")->fetch(PDO::FETCH_ASSOC);
$ok(is_array($base) && (int)$base['id']>0 && (int)$base['batch']===1 &&
    (int)$db->query('SELECT count(*) FROM public.migrations')->fetchColumn()===1,'single_original_ledger');
$ok($db->query("SELECT to_regprocedure('sites_preview.review_version(uuid,uuid,integer,text,text,text)')")->fetchColumn()===null &&
    (int)$db->query("SELECT count(*) FROM pg_attribute WHERE attrelid='sites_preview.reviews'::regclass AND attname='note' AND NOT attisdropped")->fetchColumn()===0,
    'real_v1_has_no_notes_api_or_column');
$repository->log('fixture_unrelated_upgrade_sentinel',7);
$upgradeStage='populate_legacy_history';
$legacyConnections=[];$legacyWorkspaces=[];
foreach (['author','editor','other'] as $name) {
    $legacyConnections[$name]=$runtimeConnection($name);
    $legacyWorkspaces[$name]=new PrivatePreviewWorkspace($legacyConnections[$name]);
}
$projectA='81000000-0000-4000-8000-000000000001';$projectB='81000000-0000-4000-8000-000000000002';
$companyA=$material['author']['company_id'];$companyB=$material['other']['company_id'];
$brief=static fn(int $revision):SiteBrief=>new SiteBrief('onepage','Histórico gerenciado V1',
    'Versão fictícia '.$revision,'Conteúdo de demonstração anterior ao upgrade.','Conheça',['Exemplo sintético']);
$v1=$legacyWorkspaces['author']->createDemonstration($projectA,$companyA,$brief(1));
$legacyWorkspaces['editor']->review($projectA,$companyA,1,$v1['sha256'],'changes_requested');
$v2=$legacyWorkspaces['author']->revise($projectA,$companyA,1,$brief(2));
$legacyWorkspaces['editor']->review($projectA,$companyA,2,$v2['sha256'],'approved_internal');
$v3=$legacyWorkspaces['author']->revise($projectA,$companyA,2,$brief(3));
$otherVersion=$legacyWorkspaces['other']->createDemonstration($projectB,$companyB,
    new SiteBrief('landing_page','Segunda empresa fictícia','Outra empresa preservada',
        'Conteúdo sintético independente.','Conheça',['Outro exemplo']));
$legacyRows=$db->query('SELECT revision,decision FROM sites_preview.reviews ORDER BY revision')->fetchAll(PDO::FETCH_ASSOC);
$ok($legacyRows===[['revision'=>1,'decision'=>'changes_requested'],['revision'=>2,'decision'=>'approved_internal']],
    'old_five_argument_api_created_real_legacy_reviews');
$ok($legacyService->check($legacyManifest)['status']==='verified','populated_v1_still_verified');
$marker=json_decode($db->query("SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname='sites_preview'")->fetchColumn(),true,8,JSON_THROW_ON_ERROR);
$ok($marker['kind']==='SITES_PREVIEW_MANAGED_V1' && $marker['contract_sha256']===PrivatePreviewProvisioning::contractSha(),
    'original_v1_marker_and_contract');
$upgradeManifest=['schema'=>'SITES_PREVIEW_UPGRADE_V1','source_contract_sha256'=>PrivatePreviewProvisioning::contractSha(),
    'target_contract_sha256'=>hash('sha256',"sites.preview.contract.v2\0".
        file_get_contents($upgradeRoot.'/contracts/private-preview/001-workspace.sql')."\0".
        file_get_contents($upgradeRoot.'/contracts/private-preview/002-review-notes.sql')),
    'target'=>$upgradeTarget,'principals'=>$legacyManifest['principals']];
$writeManifest($upgradeManifest);
$state=['schema'=>'SITES_PREVIEW_UPGRADE_FIXTURE_V1','source_commit'=>'ad649b3f10bdee93837e9443efc9bf4b1edeb85c',
    'manifest'=>$upgradeManifest,'base'=>$base,'marker'=>$marker,'snapshot'=>upgrade_fixture_snapshot($db),
    'history'=>upgrade_fixture_history($db),'projects'=>['a'=>$projectA,'b'=>$projectB],
    'companies'=>['a'=>$companyA,'b'=>$companyB],'versions'=>['one'=>$v1,'two'=>$v2,'three'=>$v3,'other'=>$otherVersion]];
$mask=umask(0077);
try {
    $stream=fopen($upgradeDirectory.'/state.json','xb');
    $json=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    if ($stream===false || fwrite($stream,$json)!==strlen($json)) upgrade_fixture_deny('state_write');
    fclose($stream);
} finally {umask($mask);}
echo "PREVIEW UPGRADE V1 SEED PASS: $checks checks; exact pinned historical provisioner, owner/grants, populated independent companies, original ledger and legacy five-argument reviews; no operational authority\n";
