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
        public string $stylePreset = 'classic',
        public array $faq = [],
        public array $highlights = [],
        public array $processSteps = [],
        public array $sectionOrder = ['highlights','process','faq'],
    ) {
        if (!in_array($productCode, ['onepage', 'landing_page'], true)) {
            throw new InvalidArgumentException('Unsupported Imagine Sites product.');
        }
        if (!in_array($stylePreset,['classic','modern','warm'],true)) {
            throw new InvalidArgumentException('Unsupported fictional visual preset.');
        }
        foreach ([
            'brand' => [$brand, 120],
            'headline' => [$headline, 180],
            'description' => [$description, 600],
            'callToAction' => [$callToAction, 80],
        ] as $label => [$value, $max]) {
            if (trim($value) === '' || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid plain text: '.$label);
            }
        }
        if (count($benefits) < 1 || count($benefits) > 8) {
            throw new InvalidArgumentException('One to eight benefits required.');
        }
        // Optional structured FAQs: editor-controlled plain text only.
        // Never accept URLs, scripts, raw markup, links or PII-bearing forms.
        if (count($faq) > 6 || !array_is_list($faq)) {
            throw new InvalidArgumentException('At most six structured FAQ entries.');
        }
        $normalizedQuestions = [];
        foreach ($faq as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['question', 'answer']) {
                throw new InvalidArgumentException('Invalid FAQ entry structure.');
            }
            foreach (['question' => 180, 'answer' => 900] as $field => $max) {
                $value = $entry[$field];
                if (!is_string($value) || trim($value) === '' ||
                    strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') ||
                    preg_match('/[\\x00-\\x1f\\x7f]/u', $value) ||
                    preg_match('~(?:https?://|javascript:|data:)~i', $value)) {
                    throw new InvalidArgumentException('Unsafe FAQ plain text.');
                }
            }
            $key = mb_strtolower(trim($entry['question']), 'UTF-8');
            if (isset($normalizedQuestions[$key])) {
                throw new InvalidArgumentException('Duplicate FAQ question.');
            }
            $normalizedQuestions[$key] = true;
        }
        // Optional static service highlights, never data collection forms.
        if (count($highlights) > 4 || !array_is_list($highlights)) {
            throw new InvalidArgumentException('At most four highlight entries.');
        }
        $seenHighlightTitles = [];
        foreach ($highlights as $item) {
            if (!is_array($item) || array_keys($item) !== ['title', 'description']) {
                throw new InvalidArgumentException('Invalid highlight fields.');
            }
            foreach (['title' => 100, 'description' => 300] as $field => $max) {
                $value = $item[$field];
                if (!is_string($value) || trim($value) === '' ||
                    strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') ||
                    preg_match('/[\\x00-\\x1f\\x7f]/u', $value) ||
                    preg_match('~(?:https?://|javascript:|data:)~i', $value)) {
                    throw new InvalidArgumentException('Unsafe highlight copy.');
                }
            }
            $normalized = mb_strtolower(trim($item['title']), 'UTF-8');
            if (isset($seenHighlightTitles[$normalized])) {
                throw new InvalidArgumentException('Duplicate highlight title.');
            }
            $seenHighlightTitles[$normalized] = true;
        }

        // Section layout is an editor-controlled permutation only. It cannot
        // add scripts, forms, arbitrary CSS, remote assets or new page regions.
        $expectedSections=['faq','highlights','process'];
        $receivedSections=$sectionOrder;
        if (!array_is_list($receivedSections) ||
            count($receivedSections)!==3) {
            throw new InvalidArgumentException('Invalid static section order.');
        }
        foreach($receivedSections as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Invalid section order member.');
            }
        }
        sort($receivedSections,SORT_STRING);
        if ($receivedSections!==$expectedSections) {
            throw new InvalidArgumentException('Section order must be a known permutation.');
        }

        // Optional, ordered "how it works" cards. A process step is always
        // static editorial copy; it is never a form, submission or action.
        if (count($processSteps) > 5 || !array_is_list($processSteps)) {
            throw new InvalidArgumentException('At most five process steps.');
        }
        $seenProcessTitles = [];
        foreach ($processSteps as $step) {
            if (!is_array($step) || array_keys($step) !== ['title', 'description']) {
                throw new InvalidArgumentException('Invalid process step fields.');
            }
            foreach (['title' => 100, 'description' => 350] as $field => $max) {
                $value = $step[$field];
                if (!is_string($value) || trim($value) === '' ||
                    strlen($value) > $max || !mb_check_encoding($value, 'UTF-8') ||
                    preg_match('/[\x00-\x1f\x7f]/u', $value) ||
                    preg_match('~(?:https?://|javascript:|data:)~i', $value)) {
                    throw new InvalidArgumentException('Unsafe process step text.');
                }
            }
            $normalized = mb_strtolower(trim($step['title']), 'UTF-8');
            if (isset($seenProcessTitles[$normalized])) {
                throw new InvalidArgumentException('Duplicate process step title.');
            }
            $seenProcessTitles[$normalized] = true;
        }

        foreach ($benefits as $benefit) {
            if (!is_string($benefit) || trim($benefit) === '' || strlen($benefit) > 180 ||
                !mb_check_encoding($benefit, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid benefit list.');
            }
        }
    }
}
