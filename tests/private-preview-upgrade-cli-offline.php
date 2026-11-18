<?php
declare(strict_types=1);

// Exercise only help and argument refusals, before custody checks or Laravel
// boot. Child processes cannot read /run/secrets: open_basedir is the repo.
// No manifest, credentials, database, account or schema is created or opened.
$root=dirname(__DIR__);
$cli=$root.'/ops/preview-candidate/provision.php';
$checks=0;
$ok=static function(bool $value,string $label)use(&$checks):void {
    if (!$value) throw new RuntimeException($label);
    $checks++;
};
$run=static function(array $arguments)use($root,$cli):array {
    $pipes=[];
    $process=proc_open([PHP_BINARY,'-n','-d','open_basedir='.$root,$cli,...$arguments],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    if (!is_resource($process)) throw new RuntimeException('Unable to start isolated CLI argument check');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$stdout,$stderr];
};
[$exit,$stdout,$stderr]=$run(['--help']);
$ok($exit===0 && $stderr==='' &&
    str_contains($stdout,'plan|check --manifest=/run/secrets/preview_provisioning') &&
    str_contains($stdout,'upgrade-plan|upgrade-check --manifest=/run/secrets/preview_upgrade') &&
    str_contains($stdout,'--authorize-apply=SITES_PREVIEW_ONLY_APPROVED') &&
    str_contains($stdout,'--authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED'),
    'help advertises separate fresh and upgrade guards without loading custody or application');

$fresh='--manifest=/run/secrets/preview_provisioning';
$upgrade='--manifest=/run/secrets/preview_upgrade';
$sha='--plan-sha='.str_repeat('a',64);
$freshGuard='--authorize-apply=SITES_PREVIEW_ONLY_APPROVED';
$upgradeGuard='--authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED';
$cases=[
    [[], 'operation'],
    [['unknown'], 'operation'],
    [['--help',$fresh], 'operation'],
    [['upgrade-plan'], 'manifest_custody'],
    [['upgrade-plan',$fresh], 'manifest_custody'],
    [['plan',$upgrade], 'manifest_custody'],
    [['upgrade-apply',$fresh,$sha,$upgradeGuard], 'manifest_custody'],
    [['apply',$upgrade,$sha,$freshGuard], 'manifest_custody'],
    [['upgrade-plan','--manifest=/tmp/preview_upgrade'], 'manifest_custody'],
    [['upgrade-plan',$upgrade,$upgrade], 'arguments'],
    [['upgrade-apply',$upgrade,$sha,$sha,$upgradeGuard], 'arguments'],
    [['upgrade-apply',$upgrade,$sha,$upgradeGuard,$upgradeGuard], 'arguments'],
    [['apply',$fresh,$sha,$freshGuard,$freshGuard], 'arguments'],
    [['upgrade-plan','--manifest='], 'arguments'],
    [['upgrade-plan',$upgrade,'--unknown=FICTIONAL_SENTINEL_MUST_NOT_BE_ECHOED'], 'arguments'],
    [['upgrade-plan',$upgrade,'--authorize-upgrade'], 'arguments'],
    [['upgrade-plan',$upgrade,'unexpected-positional-input'], 'arguments'],
    [['apply',$fresh], 'reviewed_apply_required'],
    [['apply',$fresh,$sha], 'reviewed_apply_required'],
    [['apply',$fresh,$freshGuard], 'reviewed_apply_required'],
    [['apply',$fresh,$sha,'--authorize-apply=SITES_PREVIEW_UPGRADE_ONLY_APPROVED'], 'reviewed_apply_required'],
    [['apply',$fresh,$sha,$upgradeGuard], 'unexpected_write_options'],
    [['apply',$fresh,$sha,$freshGuard,$upgradeGuard], 'unexpected_write_options'],
    [['upgrade-apply',$upgrade], 'reviewed_upgrade_required'],
    [['upgrade-apply',$upgrade,$sha], 'reviewed_upgrade_required'],
    [['upgrade-apply',$upgrade,$upgradeGuard], 'reviewed_upgrade_required'],
    [['upgrade-apply',$upgrade,$sha,'--authorize-upgrade=SITES_PREVIEW_ONLY_APPROVED'], 'reviewed_upgrade_required'],
    [['upgrade-apply',$upgrade,'--plan-sha='.str_repeat('A',64),$upgradeGuard], 'reviewed_upgrade_required'],
    [['upgrade-apply',$upgrade,'--plan-sha='.str_repeat('a',63),$upgradeGuard], 'reviewed_upgrade_required'],
    [['upgrade-apply',$upgrade,$sha,$freshGuard], 'unexpected_write_options'],
    [['upgrade-apply',$upgrade,$sha,$upgradeGuard,$freshGuard], 'unexpected_write_options'],
];
foreach (['plan','check','upgrade-plan','upgrade-check'] as $operation) {
    $manifest=str_starts_with($operation,'upgrade-')?$upgrade:$fresh;
    foreach ([$sha,$freshGuard,$upgradeGuard] as $writeOption) {
        $cases[]=[[$operation,$manifest,$writeOption],'unexpected_write_options'];
    }
}
foreach ($cases as $index=>[$arguments,$reason]) {
    [$exit,$stdout,$stderr]=$run($arguments);
    $ok($exit===2 && $stdout==='' && $stderr==='PROVISION_CLI_DENIED:'.$reason."\n",
        'CLI arguments refused before custody or boot, sanitized case '.$index);
}
echo 'PREVIEW UPGRADE CLI OFFLINE PASS: '.$checks.
    " checks; help, separate custody and write guards, readonly commands, bounded errors; no secret or database access\n";
