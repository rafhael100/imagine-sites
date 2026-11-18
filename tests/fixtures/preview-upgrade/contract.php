<?php
declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Imagine\Sites\Production\{PrivatePreviewHistory,PrivatePreviewProvisioning,PrivatePreviewWorkspace,SiteBrief};

$upgradeStage='current_source_and_disposable_identity';
require __DIR__.'/bootstrap.php';
$stateFile=$upgradeDirectory.'/state.json';
if (!is_file($stateFile) || is_link($stateFile) || (fileperms($stateFile)&0777)!==0600 || fileowner($stateFile)!==0) {
    upgrade_fixture_deny('seed_state_custody');
}
$state=json_decode(file_get_contents($stateFile),true,64,JSON_THROW_ON_ERROR);
$manifest=json_decode(file_get_contents($upgradeManifestPath),true,12,JSON_THROW_ON_ERROR);
if (($state['schema']??null)!=='SITES_PREVIEW_UPGRADE_FIXTURE_V1' ||
    ($state['source_commit']??null)!=='ad649b3f10bdee93837e9443efc9bf4b1edeb85c' ||
    $manifest!==$state['manifest'] || $manifest['target']!==$upgradeTarget) upgrade_fixture_deny('seed_state_identity');
$ok((new ReflectionClass(PrivatePreviewProvisioning::class))->getFileName()===
    $upgradeRoot.'/src/Production/PrivatePreviewProvisioning.php','current_upgrade_class_loaded_separately');
$ok(upgrade_fixture_snapshot($db)===$state['snapshot'],'original_seed_catalog_ledger_and_history_present');
$service=new PrivatePreviewProvisioning($connection);
$targetManifest=['schema'=>'SITES_PREVIEW_PROVISION_V1','contract_sha256'=>$manifest['target_contract_sha256'],
    'target'=>$manifest['target'],'principals'=>$manifest['principals']];
$companyA=$state['companies']['a'];$companyB=$state['companies']['b'];
$projectA=$state['projects']['a'];$projectB=$state['projects']['b'];
$upgradeStage='readonly_upgrade_plan';
$plan=$service->planUpgrade($manifest);
$ok($plan['schema']==='SITES_PREVIEW_UPGRADE_PLAN_V1' && $plan['status']==='ready' &&
    $plan['principal_count']===3 && $plan['source_contract_sha256']===PrivatePreviewProvisioning::legacyContractSha() &&
    $plan['target_contract_sha256']===PrivatePreviewProvisioning::contractSha() &&
    $plan['can_accept_orders']===false && $plan['can_publish']===false &&
    upgrade_fixture_snapshot($db)===$state['snapshot'],'plan_is_readonly_and_source_target_pinned');
$denied(fn()=>$service->checkUpgrade($manifest),'upgrade_not_applied');
$denied(fn()=>$service->applyUpgrade($manifest,$plan['plan_sha256']),'explicit_upgrade_required');
$denied(fn()=>$service->applyUpgrade($manifest,str_repeat('0',64),true),'stale_plan');
$denied(fn()=>$service->plan($targetManifest),'managed_upgrade_required');
$denied(fn()=>$service->apply($targetManifest,$plan['plan_sha256'],true),'managed_upgrade_required');
foreach (['source_contract_sha256','target_contract_sha256'] as $key) {
    $wrong=$manifest;$wrong[$key]=str_repeat('0',64);
    $denied(fn()=>$service->planUpgrade($wrong),'upgrade_manifest');
}
$wrong=$manifest;$wrong['target']['system_identifier']=$upgradeTarget['system_identifier']==='1'?'2':'1';
$denied(fn()=>$service->planUpgrade($wrong),'sql_identity');
$wrong=$manifest;$wrong['principals'][0]['company_id']=$companyB;
$denied(fn()=>$service->planUpgrade($wrong),'binding_drift');
$ok(upgrade_fixture_snapshot($db)===$state['snapshot'],'invalid_authority_hashes_and_target_preserve_v1');

// Alter only disposable fixture state, then prove the exact refused condition.
// No test accepts a generic driver failure as evidence of a guard.
$upgradeStage='source_drift_refusals';
$db->exec('ALTER ROLE sites_preview_author NOINHERIT');
try {$denied(fn()=>$service->applyUpgrade($manifest,$plan['plan_sha256'],true),'stale_plan');}
finally {$db->exec('ALTER ROLE sites_preview_author INHERIT');}
foreach ([
    ['GRANT INSERT ON sites_preview.reviews TO sites_preview_author',
        'REVOKE INSERT ON sites_preview.reviews FROM sites_preview_author','table_acl'],
    ['ALTER ROLE sites_preview_owner LOGIN','ALTER ROLE sites_preview_owner NOLOGIN','owner_or_migration_drift'],
    ["UPDATE sites_preview.principals SET revoked_at=clock_timestamp() WHERE database_login='sites_preview_editor'",
        "UPDATE sites_preview.principals SET revoked_at=NULL WHERE database_login='sites_preview_editor'",'binding_drift'],
    ["ALTER FUNCTION sites_preview.can_access(uuid,text) SET search_path=public",
        "ALTER FUNCTION sites_preview.can_access(uuid,text) SET search_path=pg_catalog,sites_preview",'structure_drift'],
] as [$change,$restore,$reason]) {
    $db->exec($change);
    try {
        $tampered=upgrade_fixture_snapshot($db);
        $denied(fn()=>$service->planUpgrade($manifest),$reason);
        $denied(fn()=>$service->applyUpgrade($manifest,$plan['plan_sha256'],true),$reason);
        $ok(upgrade_fixture_snapshot($db)===$tampered,'denied_upgrade_never_repairs_'.$reason);
    } finally {$db->exec($restore);}
}
$expires=$db->query("SELECT expires_at::text FROM sites_preview.principals WHERE database_login='sites_preview_editor'")->fetchColumn();
$db->exec("UPDATE sites_preview.principals SET expires_at=clock_timestamp()-interval '1 minute' WHERE database_login='sites_preview_editor'");
try {$denied(fn()=>$service->planUpgrade($manifest),'binding_drift');}
finally {$restore=$db->prepare("UPDATE sites_preview.principals SET expires_at=?::timestamptz WHERE database_login='sites_preview_editor'");$restore->execute([$expires]);}
$partial=$db->prepare('INSERT INTO public.migrations(migration,batch) VALUES(?,?)');
$partial->execute([PrivatePreviewProvisioning::NOTES_MIGRATION,8]);
try {$denied(fn()=>$service->planUpgrade($manifest),'owner_or_migration_drift');}
finally {$remove=$db->prepare('DELETE FROM public.migrations WHERE migration=?');$remove->execute([PrivatePreviewProvisioning::NOTES_MIGRATION]);}
$ok(upgrade_fixture_snapshot($db)===$state['snapshot'] && $service->planUpgrade($manifest)===$plan,
    'source_is_restored_without_rewriting_history_or_rewinding_sequences');

$upgradeStage='atomic_upgrade_failures';
$originalDispatcher=$connection->getEventDispatcher();
foreach (['ddl','ledger','marker'] as $point) {
    $injected=false;$faults=new Dispatcher();
    $faults->listen(QueryExecuted::class,static function(QueryExecuted $event)use(&$injected,$point):void {
        $sql=$event->sql;
        $hit=match($point) {
            'ddl'=>str_contains($sql,'ALTER TABLE sites_preview.reviews ADD COLUMN note text'),
            'ledger'=>str_starts_with(strtolower(ltrim($sql)),'insert') && str_contains($sql,'migrations') &&
                in_array(PrivatePreviewProvisioning::NOTES_MIGRATION,$event->bindings,true),
            'marker'=>str_starts_with($sql,'COMMENT ON SCHEMA sites_preview IS ') && str_contains($sql,'SITES_PREVIEW_MANAGED_V2'),
        };
        if ($hit) {$injected=true;throw new RuntimeException('Synthetic upgrade failure');}
    });
    $connection->setEventDispatcher($faults);
    try {$denied(fn()=>$service->applyUpgrade($manifest,$plan['plan_sha256'],true),'database_or_manifest_operation');}
    finally {$connection->setEventDispatcher($originalDispatcher);}
    $ok($injected && !$db->inTransaction() && $connection->transactionLevel()===0 &&
        $db->query('SELECT current_user=session_user')->fetchColumn()===true &&
        upgrade_fixture_snapshot($db)===$state['snapshot'],'full_transaction_rollback_after_'.$point);
    $ok($service->planUpgrade($manifest)===$plan,'same_valid_source_after_'.$point.'_failure');
}

$upgradeStage='actual_cli_refusals';
if (!is_file('/run/secrets/migration_owner') || is_link('/run/secrets/migration_owner') ||
    !hash_equals($upgradePassword,trim(file_get_contents('/run/secrets/migration_owner')))) upgrade_fixture_deny('cli_password_mount');
$cliEnvironment=getenv();unset($cliEnvironment['DB_URL'],$cliEnvironment['DB_PASSWORD']);
$cliEnvironment=array_replace($cliEnvironment,[
    'APP_ENV'=>'staging','APP_DEBUG'=>'false','DB_CONNECTION'=>'pgsql','DB_HOST'=>'db','DB_PORT'=>'5432',
    'DB_DATABASE'=>'sites_preview_http_ci','DB_USERNAME'=>'postgres','DB_SSLMODE'=>'verify-full',
    'DB_SSLROOTCERT'=>'/run/ci/ca.pem','SITES_DB_PASSWORD_FILE'=>'/run/secrets/migration_owner',
]);
$launchCli=static function(array $arguments,array $environmentChanges=[])use($cliEnvironment,$upgradeRoot):array {
    $process=proc_open([PHP_BINARY,$upgradeRoot.'/ops/preview-candidate/provision.php',...$arguments],
        [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,
        $upgradeRoot.'/apps/sites',array_replace($cliEnvironment,$environmentChanges));
    if (!is_resource($process)) upgrade_fixture_deny('cli_process');
    return ['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
};
$finishCli=static function(array $child):array {
    $stdout=stream_get_contents($child['stdout']);$stderr=stream_get_contents($child['stderr']);
    fclose($child['stdout']);fclose($child['stderr']);$exit=proc_close($child['process']);
    return ['exit'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr];
};
$runCli=static fn(array $arguments,array $environmentChanges=[]):array=>$finishCli($launchCli($arguments,$environmentChanges));
$denyCli=static function(array $arguments,string $reason,int $exit=2)use($runCli,$ok):void {
    $result=$runCli($arguments);
    $prefix=$exit===2?'PROVISION_CLI_DENIED:':'PROVISION_DENIED:';
    $ok($result===['exit'=>$exit,'stdout'=>'','stderr'=>$prefix.$reason."\n"],'actual_cli_'.$reason);
};
$cliManifest='--manifest=/run/secrets/preview_upgrade';
$cliApply=['upgrade-apply',$cliManifest,'--plan-sha='.$plan['plan_sha256'],
    '--authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED'];
$cliPlan=$runCli(['upgrade-plan',$cliManifest]);
$ok($cliPlan['exit']===0 && $cliPlan['stderr']==='' &&
    json_decode($cliPlan['stdout'],true,16,JSON_THROW_ON_ERROR)===$plan,'actual_cli_plan_matches_service_over_tls');
$denyCli(['upgrade-check',$cliManifest],'upgrade_not_applied',1);
$denyCli(['upgrade-apply',$cliManifest,'--plan-sha='.$plan['plan_sha256']],'reviewed_upgrade_required');
$denyCli([...$cliApply,'--authorize-apply=SITES_PREVIEW_ONLY_APPROVED'],'unexpected_write_options');
$denyCli(['upgrade-plan','--manifest=/run/secrets/preview_provisioning'],'manifest_custody');
$denyCli(['upgrade-plan',$cliManifest,$cliManifest],'arguments');
chmod($upgradeManifestPath,0644);
try {$denyCli(['upgrade-plan',$cliManifest],'manifest_custody');}
finally {chmod($upgradeManifestPath,0600);clearstatcache(true,$upgradeManifestPath);}
$wrong=$manifest;$wrong['target']['system_identifier']=$upgradeTarget['system_identifier']==='1'?'2':'1';$writeManifest($wrong);
try {$denyCli(['upgrade-plan',$cliManifest],'sql_identity',1);}
finally {$writeManifest($manifest);}
$wrongTrust=$runCli(['upgrade-plan',$cliManifest],['DB_SSLROOTCERT'=>'/run/ci/server.pem']);
$ok($wrongTrust===['exit'=>1,'stdout'=>'','stderr'=>"PROVISION_DENIED:database_or_manifest_operation\n"],
    'actual_cli_rejects_wrong_tls_trust_without_private_diagnostics');
$ok(upgrade_fixture_snapshot($db)===$state['snapshot'],'cli_refusals_preserve_original_v1_state');

// Both actual CLI processes must reach the same transaction lock before it is
// released. One applies; the second rechecks its now-stale reviewed plan.
$upgradeStage='concurrent_actual_cli_upgrade';
$children=[];$completed=[];$waiting=0;
$db->beginTransaction();
try {
    $db->query("SELECT pg_advisory_xact_lock(hashtextextended('sites_preview.provision.v1',0))")->fetchColumn();
    $children[]=$launchCli($cliApply);$children[]=$launchCli($cliApply);
    $deadline=microtime(true)+4.0;
    do {
        $db->query('SELECT pg_stat_clear_snapshot()')->fetchColumn();
        $waiting=(int)$db->query("SELECT count(*) FROM pg_stat_activity
            WHERE datname=current_database() AND usename='postgres' AND pid<>pg_backend_pid()
            AND wait_event_type='Lock' AND wait_event='advisory'
            AND query LIKE '%sites_preview.provision.v1%'")->fetchColumn();
        if ($waiting===2) break;
        usleep(20000);
    } while (microtime(true)<$deadline);
} finally {
    if ($db->inTransaction()) $db->commit();
    foreach ($children as $child) $completed[]=$finishCli($child);
}
$ok($waiting===2,'two_real_cli_processes_compete_on_shared_provision_lock');
$success=array_values(array_filter($completed,static fn(array $r):bool=>$r['exit']===0));
$refused=array_values(array_filter($completed,static fn(array $r):bool=>$r['exit']!==0));
$ok(count($success)===1 && count($refused)===1 && $success[0]['stderr']==='' &&
    $refused[0]===['exit'=>1,'stdout'=>'','stderr'=>"PROVISION_DENIED:stale_plan\n"],
    'only_one_concurrent_upgrade_applies_and_the_other_is_stale');
$receipt=json_decode($success[0]['stdout'],true,16,JSON_THROW_ON_ERROR);
$ok($receipt['schema']==='SITES_PREVIEW_UPGRADE_RESULT_V1' && $receipt['status']==='upgraded' &&
    $receipt['changed']===true && $receipt['source_contract_sha256']===$manifest['source_contract_sha256'] &&
    $receipt['target_contract_sha256']===$manifest['target_contract_sha256'] &&
    $receipt['can_accept_orders']===false && $receipt['can_publish']===false,'actual_cli_upgrade_receipt_is_nonoperational');

$upgradeStage='post_upgrade_identity_and_history';
$ok(upgrade_fixture_history($db)===$state['history'],'upgrade_preserves_every_legacy_row_and_binding');
$ledger=$db->query('SELECT id,migration,batch FROM public.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$base=array_values(array_filter($ledger,static fn(array $r):bool=>$r['migration']===PrivatePreviewProvisioning::MIGRATION));
$notes=array_values(array_filter($ledger,static fn(array $r):bool=>$r['migration']===PrivatePreviewProvisioning::NOTES_MIGRATION));
$unrelated=array_values(array_filter($ledger,static fn(array $r):bool=>$r['migration']==='fixture_unrelated_upgrade_sentinel'));
$ok(count($ledger)===3 && $base===[$state['base']] && count($notes)===1 && (int)$notes[0]['batch']===8 &&
    $unrelated===[['id'=>2,'migration'=>'fixture_unrelated_upgrade_sentinel','batch'=>7]],
    'only_notes_migration_appended_in_new_batch_with_base_and_unrelated_ledger_unchanged');
$markerRaw=$db->query("SELECT obj_description(oid,'pg_namespace') FROM pg_namespace WHERE nspname='sites_preview'")->fetchColumn();
$marker=json_decode($markerRaw,true,12,JSON_THROW_ON_ERROR);
$ok($marker['kind']==='SITES_PREVIEW_MANAGED_V2' && $marker['contract_sha256']===$manifest['target_contract_sha256'] &&
    $marker['upgrade']===[
        'base_migration'=>['batch'=>(int)$state['base']['batch'],'id'=>(int)$state['base']['id']],
        'notes_migration'=>['batch'=>(int)$notes[0]['batch'],'id'=>(int)$notes[0]['id']],
        'schema'=>'SITES_PREVIEW_UPGRADE_LINEAGE_V1','source_contract_sha256'=>$manifest['source_contract_sha256'],
        'source_structure_sha256'=>$state['marker']['structure_sha256'],
    ],'upgrade_lineage_records_real_source_structure_and_ledger_identities');
$oldFunction=array_values(array_filter($state['snapshot'][2],static fn(array $f):bool=>
    $f['signature']==='sites_preview.review_version(uuid,uuid,integer,text,text)'))[0];
$afterFunction=$db->query("SELECT oid::text,proowner::regrole::text AS owner,proacl::text FROM pg_proc
    WHERE oid='sites_preview.review_version(uuid,uuid,integer,text,text)'::regprocedure")->fetch(PDO::FETCH_ASSOC);
$ok($afterFunction===['oid'=>$oldFunction['oid'],'owner'=>$oldFunction['owner'],'proacl'=>$oldFunction['proacl']],
    'legacy_five_argument_identity_owner_and_acl_preserved');
$ok((int)$db->query('SELECT count(*) FROM sites_preview.reviews WHERE note IS NULL')->fetchColumn()===2 &&
    (int)$db->query("SELECT count(*) FROM pg_proc WHERE pronamespace='sites_preview'::regnamespace")->fetchColumn()===6 &&
    $service->checkUpgrade($manifest)['status']==='verified' && $service->check($targetManifest)['status']==='verified',
    'legacy_null_notes_six_functions_and_both_verifiers_agree');
$beforeNoop=upgrade_fixture_snapshot($db);$currentPlan=$service->planUpgrade($manifest);
$cliCheck=$runCli(['upgrade-check',$cliManifest]);
$cliNoop=$runCli(['upgrade-apply',$cliManifest,'--plan-sha='.$currentPlan['plan_sha256'],
    '--authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED']);
$noop=json_decode($cliNoop['stdout'],true,16,JSON_THROW_ON_ERROR);
$ok($currentPlan['status']==='already_upgraded' && $currentPlan['actions']===[] &&
    $cliCheck['exit']===0 && $cliCheck['stderr']==='' &&
    json_decode($cliCheck['stdout'],true,16,JSON_THROW_ON_ERROR)['status']==='verified' &&
    $cliNoop['exit']===0 && $cliNoop['stderr']==='' && $noop['status']==='already_upgraded' && $noop['changed']===false &&
    upgrade_fixture_snapshot($db)===$beforeNoop,'real_cli_check_and_repeat_are_readonly');
$denied(fn()=>$service->applyUpgrade($manifest,$plan['plan_sha256'],true),'stale_plan');
$altered=$marker;$altered['upgrade']['base_migration']['id']++;
$db->exec('COMMENT ON SCHEMA sites_preview IS '.$db->quote(json_encode($altered,JSON_THROW_ON_ERROR)));
try {$denied(fn()=>$service->checkUpgrade($manifest),'upgrade_lineage');$denied(fn()=>$service->check($targetManifest),'upgrade_lineage');}
finally {$db->exec('COMMENT ON SCHEMA sites_preview IS '.$db->quote($markerRaw));}
$withoutLineage=$marker;unset($withoutLineage['upgrade']);
$db->exec('COMMENT ON SCHEMA sites_preview IS '.$db->quote(json_encode($withoutLineage,JSON_THROW_ON_ERROR)));
try {
    $denied(fn()=>$service->planUpgrade($manifest),'upgrade_source_required');
    $denied(fn()=>$service->check($targetManifest),'owner_or_migration_drift');
} finally {$db->exec('COMMENT ON SCHEMA sites_preview IS '.$db->quote($markerRaw));}
$ok(upgrade_fixture_snapshot($db)===$beforeNoop,'lineage_refusals_preserve_valid_upgraded_state');

$upgradeStage='upgraded_runtime_and_note_custody';
$runtime=[];$workspaces=[];
foreach (['author','editor','other'] as $name) {
    $runtime[$name]=$runtimeConnection($name);$workspaces[$name]=new PrivatePreviewWorkspace($runtime[$name]);
    $workspaces[$name]->assertSafeRuntime();
    $ok($runtime[$name]->query("SELECT has_function_privilege(current_user,
        'sites_preview.review_version(uuid,uuid,integer,text,text,text)','EXECUTE')")->fetchColumn()===($name==='editor'),
        'only_existing_reviewer_gains_note_api_'.$name);
}
$sqlDenied=static function(callable $operation,string $expected,string $label)use($ok):void {
    try {$operation();} catch (PDOException $error) {$ok($error->getCode()===$expected,$label);return;}
    $ok(false,$label);
};
$legacyReview=$runtime['editor']->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?)');
$sqlDenied(fn()=>$legacyReview->execute([$projectA,$companyA,3,$state['versions']['three']['sha256'],'changes_requested']),
    '22023','legacy_api_cannot_create_new_blank_adjustment');
$cross=$runtime['editor']->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?,?)');
$sqlDenied(fn()=>$cross->execute([$projectB,$companyB,1,$state['versions']['other']['sha256'],'changes_requested','Outra empresa']),
    '42501','authorized_reviewer_cannot_attach_note_to_other_company_valid_version');
$wrongDuty=$runtime['author']->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?,?)');
$sqlDenied(fn()=>$wrongDuty->execute([$projectA,$companyA,3,$state['versions']['three']['sha256'],'changes_requested','Mesmo autor']),
    '42501','author_never_receives_review_execution');
$ok(upgrade_fixture_history($db)===$state['history'],'all_negative_note_writes_preserve_legacy_history');
$note="  Ajuste fictício após o upgrade.\nTexto literal <strong>sem marcação</strong> 漢😀  ";
$workspaces['editor']->review($projectA,$companyA,3,$state['versions']['three']['sha256'],'changes_requested',$note);
$history=(new PrivatePreviewHistory($runtime['author']))->page($companyA,$projectA);
$ok($history['current_revision']===3 && $history['current_reviews'][0]['note']===$note &&
    $history['current_reviews'][0]['sha256']===$state['versions']['three']['sha256'] &&
    $history['reviews'][1]['note']===null && $history['reviews'][2]['note']===null,
    'new_note_and_unmodified_legacy_null_opinions_remain_readable');
$v4=$workspaces['author']->revise($projectA,$companyA,3,
    new SiteBrief('onepage','Histórico gerenciado V1','Versão fictícia 4','Continuidade do exemplo.','Conheça',['Exemplo']));
$legacyReview->execute([$projectA,$companyA,4,$v4['sha256'],'approved_internal']);
$approved=(new PrivatePreviewHistory($runtime['author']))->page($companyA,$projectA);
$ok($approved['current_reviews'][0]['decision']==='approved_internal' && $approved['current_reviews'][0]['note']===null,
    'legacy_approval_remains_usable_after_upgrade');
try {(new PrivatePreviewHistory($runtime['other']))->page($companyB,$projectA);$ok(false,'other_company_history_refused');}
catch (OutOfBoundsException) {$ok(true,'other_company_history_refused');}
$v5=$workspaces['author']->revise($projectA,$companyA,4,
    new SiteBrief('onepage','Histórico gerenciado V1','Versão fictícia 5','Revogação ainda respeitada.','Conheça',['Exemplo']));
$db->exec("UPDATE sites_preview.principals SET revoked_at=clock_timestamp() WHERE database_login='sites_preview_editor'");
try {$sqlDenied(fn()=>$cross->execute([$projectA,$companyA,5,$v5['sha256'],'changes_requested','Parecer revogado']),
    '42501','reused_reviewer_connection_observes_revocation_after_upgrade');}
finally {$db->exec("UPDATE sites_preview.principals SET revoked_at=NULL WHERE database_login='sites_preview_editor'");}
$beforeDown=upgrade_fixture_snapshot($db);
$migration=require $upgradeRoot.'/apps/sites/database/migrations/'.PrivatePreviewProvisioning::NOTES_MIGRATION.'.php';
$downDenied=false;
try {$migration->down();} catch (RuntimeException $error) {
    $downDenied=$error->getMessage()==='Preserve preview review notes; recovery review required';
}
$ok($downDenied && upgrade_fixture_snapshot($db)===$beforeDown && $service->checkUpgrade($manifest)['status']==='verified',
    'populated_note_rollback_is_refused_without_history_loss_or_lineage_repair');
$ok($db->query('SELECT value FROM public.provision_upgrade_sentinel WHERE id=1')->fetchColumn()==='Preserve unrelated synthetic state',
    'unrelated_data_remains_unchanged');
echo "PREVIEW UPGRADE CONTRACT PASS: $checks checks; real managed V1 source, populated history, exact identity, rollback at three boundaries, two-process actual CLI race, immutable ledger lineage, repeat no-op, scoped notes and revocation; no operational authority\n";
