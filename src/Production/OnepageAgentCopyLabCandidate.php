<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Isolated protocol seam: Sites issues one versioned, synthetic task to a
 * simulated Imagine AI Agents worker and accepts only sites.ai-copy.v1.
 *
 * No default worker, HTTP client, model/provider, Portal authority, secrets,
 * persistent deduplication, or production binding. An explicit *mock* worker
 * is invoked at most once; retry/timeout policies belong to a future trusted
 * S2S adapter. Never interpret this result as customer acceptance/publication.
 */
final class OnepageAgentCopyLabCandidate
{
    public const AGENT_VERSION = 'imagine.onepage-agent.mock.v1';
    private const DENIED = 'ONEPAGE_AGENT_COPY_DENIED';

    /**
     * @param ?Closure $mockWorker fn(string $taskJson): string $responseJson
     * @return array{taskJson:string,acceptedCopyJson:string,artifact:ReleaseArtifact,evidence:array<string,mixed>}
     */
    public static function execute(
        string $manifestBytes,
        string $sourceBytes,
        string $officialSyntheticBrand,
        string $mockRunRef,
        ?Closure $mockWorker = null,
    ): array {
        try {
            if ($mockWorker === null ||
                preg_match('/\Amock-[a-z0-9][a-z0-9-]{0,63}\z/D', $mockRunRef) !== 1) {
                throw new RuntimeException();
            }

            // The immutable fixture is validated for exact byte descriptors.
            // It is NOT an authenticated Portal read. Do not send its body,
            // tenant/order/site, commercial terms, or brand to the agent.
            $brief = BriefSnapshotInput::fromJson($manifestBytes, $sourceBytes);
            if ($brief->productCode !== 'onepage') throw new RuntimeException();

            $binding = implode("\0", [
                $brief->tenantRef, $brief->orderRef, $brief->siteRef,
                $brief->contractRef, (string)$brief->contractRevision,
                $brief->snapshotRef, (string)$brief->sourceVersion,
                (string)$brief->briefRevision, $brief->manifestSha256,
                $brief->payloadSha256, self::AGENT_VERSION,
            ]);
            $taskRef = 'task-'.substr(hash('sha256', "sites.onepage-agent-task.v1\0".$binding), 0, 32);
            $correlationRef = 'corr-'.substr(hash('sha256', "sites.onepage-agent-correlation.v1\0".$binding), 0, 32);
            $task = [
                'schemaVersion' => 'sites.onepage-agent-task.v1',
                'taskRef' => $taskRef,
                'correlationRef' => $correlationRef,
                'agentVersion' => self::AGENT_VERSION,
                'briefRevision' => $brief->briefRevision,
                'briefSha256' => $brief->payloadSha256,
                // Fictional, static non-PII hints; not read from the rich source.
                'copyHints' => [
                    'subject' => 'Serviços demonstrativos de manutenção de bicicletas',
                    'audience' => 'Público inteiramente fictício',
                    'tone' => 'Clareza e acessibilidade',
                ],
            ];
            $taskJson = json_encode($task, JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($taskJson) || strlen($taskJson) > 2048) throw new RuntimeException();

            // Exactly one synchronous invocation. No retries, provider calls,
            // loops or network access are implemented by this candidate.
            $responseJson = $mockWorker($taskJson);
            if (!is_string($responseJson)) throw new RuntimeException();
            $response = BriefCandidateJson::decode($responseJson, 8192);
            BriefCandidateJson::keys($response, [
                'schemaVersion', 'taskRef', 'correlationRef', 'agentVersion',
                'briefRevision', 'briefSha256', 'copy',
            ]);
            if ($response->schemaVersion !== 'sites.onepage-agent-response.v1' ||
                $response->taskRef !== $taskRef ||
                $response->correlationRef !== $correlationRef ||
                $response->agentVersion !== self::AGENT_VERSION ||
                $response->briefRevision !== $brief->briefRevision ||
                $response->briefSha256 !== $brief->payloadSha256) {
                throw new RuntimeException();
            }

            BriefCandidateJson::keys($response->copy, [
                'schemaVersion', 'headline', 'description', 'callToAction', 'benefits',
            ]);
            // Reuse the existing strict v1 copy validator. Do not accept the
            // historical v4 or model-origin brand/product/HTML/authority.
            $copy = get_object_vars($response->copy);
            $briefForRender = StructuredCopyCandidate::assemble(
                'onepage', $officialSyntheticBrand, $copy, 4096,
            );
            $acceptedCopyJson = json_encode($copy, JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($acceptedCopyJson)) throw new RuntimeException();
            $artifact = (new StaticSiteRenderer())->render($briefForRender);
            if (!InertPreviewAudit::isSafe($artifact->html) ||
                !hash_equals($artifact->digest, hash('sha256', $artifact->html))) {
                throw new RuntimeException();
            }

            return [
                'taskJson' => $taskJson,
                'acceptedCopyJson' => $acceptedCopyJson,
                'artifact' => $artifact,
                'evidence' => [
                    'schemaVersion' => 'sites.onepage-agent-evidence.v1',
                    'executionMode' => 'simulated',
                    'taskRef' => $taskRef,
                    'correlationRef' => $correlationRef,
                    'agentVersion' => self::AGENT_VERSION,
                    'runRef' => $mockRunRef,
                    'tenantRef' => $brief->tenantRef,
                    'orderRef' => $brief->orderRef,
                    'siteRef' => $brief->siteRef,
                    'sourceVersion' => $brief->sourceVersion,
                    'briefRevision' => $brief->briefRevision,
                    'manifestSha256' => $brief->manifestSha256,
                    'briefSha256' => $brief->payloadSha256,
                    'taskSha256' => hash('sha256', $taskJson),
                    'acceptedCopySha256' => hash('sha256', $acceptedCopyJson),
                    'htmlSha256' => $artifact->digest,
                    'privatePreviewOnly' => true,
                    'clientFinalAccepted' => false,
                    'publicationAuthorized' => false,
                    'canPublish' => false,
                    'canAcceptRealOrders' => false,
                ],
            ];
        } catch (Throwable) {
            // No rich brief, model output or untrusted diagnostics are logged.
            throw new RuntimeException(self::DENIED);
        }
    }
}
