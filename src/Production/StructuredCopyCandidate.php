<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use InvalidArgumentException;
use JsonException;

/**
 * F1-29: strict adapter for an untrusted, fictional structured AI COPY response.
 * Product and brand MUST come from a separately authorized (future) Portal
 * source, never from the text generator. No provider, API, prompts or PII.
 *
 * This is not a moderation system or a real prompt-injection defense.
 */
final class StructuredCopyCandidate
{
    private const COPY_FIELDS = [
        'schemaVersion', 'headline', 'description', 'callToAction', 'benefits',
    ];

    /** @param array<string,mixed> $candidate */
    public static function assemble(
        string $officialProductCode,
        string $officialBrand,
        array $candidate,
        int $maxBytes = 4096,
    ): SiteBrief {
        if ($maxBytes < 512 || $maxBytes > 32768) {
            throw new InvalidArgumentException('Invalid synthetic output size budget.');
        }
        $actual = array_keys($candidate);
        $expected = self::COPY_FIELDS;
        sort($actual);
        sort($expected);
        if ($actual !== $expected || ($candidate['schemaVersion'] ?? null) !== 'sites.ai-copy.v1') {
            throw new DomainException('untrusted_ai_response_schema');
        }
        try {
            $encoded = json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new DomainException('malformed_ai_response', previous:$e);
        }
        if (strlen($encoded) > $maxBytes) {
            throw new DomainException('synthetic_ai_output_budget_exceeded');
        }

        foreach (['headline','description','callToAction'] as $field) {
            if (!is_string($candidate[$field]) || !self::safePlainCopy($candidate[$field])) {
                throw new DomainException('unsafe_ai_text_'.$field);
            }
        }
        $benefits = $candidate['benefits'];
        if (!is_array($benefits) || !array_is_list($benefits)) {
            throw new DomainException('invalid_ai_benefits_list');
        }
        foreach ($benefits as $benefit) {
            if (!is_string($benefit) || !self::safePlainCopy($benefit)) {
                throw new DomainException('unsafe_ai_benefit');
            }
        }
        try {
            return new SiteBrief(
                $officialProductCode,
                $officialBrand,
                $candidate['headline'],
                $candidate['description'],
                $candidate['callToAction'],
                $benefits,
            );
        } catch (InvalidArgumentException $e) {
            throw new DomainException('invalid_ai_brief_constraints', previous:$e);
        }
    }

    private static function safePlainCopy(string $text): bool
    {
        if (!mb_check_encoding($text, 'UTF-8')) return false;
        // Fixed laboratory schema: no control chars, tags or URL schemes.
        // It deliberately rejects valid copy containing these forms; review
        // and richer content rules require a separate design.
        if (preg_match('/[\x00-\x1f\x7f<>]/u', $text)) return false;
        return !preg_match('~(?:https?://|javascript:|data:)~i', $text);
    }
}
