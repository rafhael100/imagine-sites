<?php
declare(strict_types=1);

// Explicit candidate CLI only; never required by routes or an application boot.
use Illuminate\Support\Facades\DB;
use Imagine\Sites\Production\PrivatePreviewProvisioning;

function provision_cli_denied(string $reason): never
{
    fwrite(STDERR, 'PROVISION_CLI_DENIED:'.$reason."\n");
    exit(2);
}

if (PHP_SAPI !== 'cli') provision_cli_denied('cli_required');
if (($argv[1] ?? '') === '--help' && count($argv) === 2) {
    echo "Candidate only: php provision.php plan|check --manifest=/run/secrets/preview_provisioning\n";
    echo "After reviewed authorization: php provision.php apply --manifest=/run/secrets/preview_provisioning --plan-sha=<reviewed-sha256> --authorize-apply=SITES_PREVIEW_ONLY_APPROVED\n";
    echo "Existing managed V1 candidate: php provision.php upgrade-plan|upgrade-check --manifest=/run/secrets/preview_upgrade\n";
    echo "After reviewed upgrade authorization: php provision.php upgrade-apply --manifest=/run/secrets/preview_upgrade --plan-sha=<reviewed-sha256> --authorize-upgrade=SITES_PREVIEW_UPGRADE_ONLY_APPROVED\n";
    exit(0);
}
$operation = $argv[1] ?? '';
if (!in_array($operation, ['plan', 'apply', 'check', 'upgrade-plan', 'upgrade-apply', 'upgrade-check'], true)) provision_cli_denied('operation');
$upgrade = str_starts_with($operation, 'upgrade-');
$options = [];
foreach (array_slice($argv, 2) as $argument) {
    if (!preg_match('/\A--(manifest|plan-sha|authorize-apply|authorize-upgrade)=(.+)\z/D', $argument, $match) || isset($options[$match[1]])) provision_cli_denied('arguments');
    $options[$match[1]] = $match[2];
}
if (($options['manifest'] ?? '') !== ($upgrade ? '/run/secrets/preview_upgrade' : '/run/secrets/preview_provisioning')) provision_cli_denied('manifest_custody');
if ($operation === 'apply') {
    if (isset($options['authorize-upgrade'])) provision_cli_denied('unexpected_write_options');
    if (($options['authorize-apply'] ?? '') !== 'SITES_PREVIEW_ONLY_APPROVED' ||
        !preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha'] ?? '')) provision_cli_denied('reviewed_apply_required');
} elseif ($operation === 'upgrade-apply') {
    if (isset($options['authorize-apply'])) provision_cli_denied('unexpected_write_options');
    if (($options['authorize-upgrade'] ?? '') !== 'SITES_PREVIEW_UPGRADE_ONLY_APPROVED' ||
        !preg_match('/\A[a-f0-9]{64}\z/D', $options['plan-sha'] ?? '')) provision_cli_denied('reviewed_upgrade_required');
} elseif (isset($options['authorize-apply']) || isset($options['authorize-upgrade']) || isset($options['plan-sha'])) provision_cli_denied('unexpected_write_options');
$path = $options['manifest'];
if (is_link($path) || !is_file($path) || !is_readable($path) ||
    !in_array(fileperms($path) & 0777, [0400, 0600], true) ||
    filesize($path) < 64 || filesize($path) > 32768 ||
    (function_exists('posix_geteuid') && !in_array(fileowner($path), [0, posix_geteuid()], true))) provision_cli_denied('manifest_custody');

try {
    $manifest = json_decode(file_get_contents($path), true, 12, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) provision_cli_denied('manifest');
    $root = dirname(__DIR__, 2).'/apps/sites';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $service = new PrivatePreviewProvisioning(DB::connection('pgsql'));
    $result = match ($operation) {
        'plan' => $service->plan($manifest),
        'check' => $service->check($manifest),
        'apply' => $service->apply($manifest, $options['plan-sha'], true),
        'upgrade-plan' => $service->planUpgrade($manifest),
        'upgrade-check' => $service->checkUpgrade($manifest),
        'upgrade-apply' => $service->applyUpgrade($manifest, $options['plan-sha'], true),
    };
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException && preg_match('/\APROVISION_DENIED:[a-z_]+\z/D', $e->getMessage())
        ? $e->getMessage() : 'PROVISION_DENIED:execution';
    fwrite(STDERR, $message."\n");
    exit(1);
}
