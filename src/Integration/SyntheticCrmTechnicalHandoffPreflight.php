<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use Throwable;

/**
 * F1-166: two-read binding revision fence BEFORE preparing a fixed-key,
 * metadata-only CRM handoff candidate. The source is injected and UNTRUSTED;
 * matching it is not an atomic CAS or authorization to deliver any lead.
 */
final class SyntheticCrmTechnicalHandoffPreflight
{
    public function __construct(
        private readonly ?SyntheticLeadBindingAuthoritySource $source = null
    ) {}

    public function inspect(
        SyntheticCrmLeadEnvelopeV1Draft $envelope,
        string $operation,
        int $issuedAt,
        int $expiresAt,
        int $now,
        string $nonce,
    ): SyntheticCrmTechnicalPreparationAssessment {
        if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F165_SYNTHETIC_AUTH')!=='1' ||
            getenv('SITES_F166_AUTHORITY_FENCE')!=='1') {
            return new SyntheticCrmTechnicalPreparationAssessment('LAB_DISABLED');
        }

        $check=(new SyntheticLeadBindingRevisionFence($this->source))
            ->inspect($envelope);

        // Do not even produce a synthetic wire unless two separate reads
        // claim the same active, pinned context with the same revision.
        if ($check->reasonCode!=='STABLE_MATCH_UNVERIFIED') {
            return new SyntheticCrmTechnicalPreparationAssessment($check->reasonCode);
        }

        try {
            $candidate=SyntheticCrmTechnicalHandoffV1::draft(
                $envelope,$operation,$issuedAt,$expiresAt,$now,$nonce
            );
        } catch(Throwable) {
            return new SyntheticCrmTechnicalPreparationAssessment('HANDOFF_REJECTED');
        }

        // This still cannot prove who supplied the source, authenticate a
        // service or pin a durable DB/Portal epoch after the two reads.
        return new SyntheticCrmTechnicalPreparationAssessment(
            'LAB_WIRE_PREPARED_UNTRUSTED',$candidate
        );
    }
}
