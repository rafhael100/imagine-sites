<?php
declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use Throwable;

/**
 * CI-only process boundary for the already-pinned synthetic OnePage worker.
 * No shell, inherited environment, provider, retry, logs or authority grant.
 * The worker contract and rendered copy are validated by the existing Sites
 * OnepageAgentCopyLabCandidate, not by this process supervisor.
 */
final class OnepageBoundedWorkerProcessCandidate
{
    private const DENIED = 'ONEPAGE_WORKER_BOUNDARY_DENIED';

    public static function run(
        string $taskJson,
        string $workerScript,
        string $trustedCheckoutRoot,
        int $deadlineMs = 2500,
    ): string {
        $process = null;
        $pipes = [];
        $finished = false;
        try {
            if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
                getenv('SITES_ONEPAGE_AGENT_PROCESS_LAB') !== '1' ||
                strlen($taskJson) < 1 || strlen($taskJson) > 2048 ||
                $deadlineMs < 50 || $deadlineMs > 5000) {
                throw new RuntimeException();
            }
            $root = realpath($trustedCheckoutRoot);
            $script = realpath($workerScript);
            if ($root === false || $script === false ||
                !str_starts_with($script, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) ||
                basename($script) !== 'onepage_copy_process_worker.php' ||
                !is_file($script)) {
                throw new RuntimeException();
            }
            $process = proc_open(
                [PHP_BINARY, $script],
                [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
                $pipes,
                dirname($script),
                ['GITHUB_ACTIONS' => 'true', 'IMAGINE_ONEPAGE_PROCESS_LAB' => '1'],
            );
            if (!is_resource($process) || count($pipes) !== 3) {
                throw new RuntimeException();
            }
            foreach ($pipes as $pipe) {
                if (!stream_set_blocking($pipe, false)) throw new RuntimeException();
            }
            $start = hrtime(true);
            $offset = 0;
            $stdout = '';
            $stdinClosed = false;
            $observedExit = null;
            while (true) {
                if ((hrtime(true) - $start) > $deadlineMs * 1000000) {
                    throw new RuntimeException();
                }
                if (!$stdinClosed) {
                    $sent = @fwrite($pipes[0], substr($taskJson, $offset));
                    if ($sent === false) throw new RuntimeException();
                    $offset += $sent;
                    if ($offset === strlen($taskJson)) {
                        fclose($pipes[0]);
                        $stdinClosed = true;
                    }
                }
                $out = @fread($pipes[1], 8193);
                $err = @fread($pipes[2], 257);
                if ($out === false || $err === false || $err !== '') {
                    throw new RuntimeException();
                }
                $stdout .= $out;
                if (strlen($stdout) > 8192) throw new RuntimeException();
                $status = proc_get_status($process);
                if (!$status['running'] && $observedExit === null) {
                    $observedExit = $status['exitcode'];
                }
                if (!$status['running'] && $stdinClosed &&
                    feof($pipes[1]) && feof($pipes[2])) {
                    if ($observedExit !== 0 || $stdout === '') throw new RuntimeException();
                    $finished = true;
                    return $stdout;
                }
                usleep(10000);
            }
        } catch (Throwable) {
            // No stdout, stderr, task payload or provider errors are surfaced.
            throw new RuntimeException(self::DENIED);
        } finally {
            if (is_resource($process) && !$finished) @proc_terminate($process, 9);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) fclose($pipe);
            }
            if (is_resource($process)) @proc_close($process);
        }
    }
}
