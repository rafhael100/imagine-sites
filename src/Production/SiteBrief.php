<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use InvalidArgumentException;

/**
 * Synthetic structured brief only. No uploads, PII, URL, raw HTML or forms.
 * Content is always treated as untrusted plain text by the renderer.
 */
final readonly class SiteBrief
{
    /** @param list<string> $benefits */
    public function __construct(
        public string $productCode,
        public string $brand,
        public string $headline,
        public string $description,
        public string $callToAction,
        public array $benefits,
    ) {
        if (!in_array($productCode, ['onepage', 'landing_page'], true)) {
            throw new InvalidArgumentException('Unsupported Imagine Sites product.');
        }
        foreach ([
            'brand' => [$brand, 120],
            'headline' => [$headline, 180],
            'description' => [$description, 600],
            'callToAction' => [$callToAction, 80],
        ] as $label => [$value, $max]) {
            if (trim($value) === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max) {
                throw new InvalidArgumentException('Invalid plain text: '.$label);
            }
        }
        if (count($benefits) < 1 || count($benefits) > 8) {
            throw new InvalidArgumentException('One to eight benefits required.');
        }
        foreach ($benefits as $benefit) {
            if (!is_string($benefit) || trim($benefit) === '' || !mb_check_encoding($benefit, 'UTF-8') ||
                mb_strlen($benefit, 'UTF-8') > 180) {
                throw new InvalidArgumentException('Invalid benefit list.');
            }
        }
    }
}
