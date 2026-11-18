<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Local technical input for the existing synthetic renderer. This format is
 * not a Portal briefing revision, commercial authority or customer acceptance.
 * No application boot, file access, database, provider or publication occurs.
 */
final class LocalSiteRendering
{
    public const MAX_INPUT_BYTES = 32768;

    /** Return the renderer's original bytes, never a normalized HTML copy. */
    public static function render(string $json): ReleaseArtifact
    {
        try {
            if ($json === '' || strlen($json) > self::MAX_INPUT_BYTES || str_contains($json, "\0")) {
                throw new RuntimeException();
            }
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
            self::rejectDuplicateKeys($json);
            if (!$decoded instanceof stdClass) throw new RuntimeException();
            $input = get_object_vars($decoded);
            $keys = ['productCode', 'brand', 'headline', 'description', 'callToAction', 'benefits'];
            if (count($input) !== count($keys) || array_diff($keys, array_keys($input))) {
                throw new RuntimeException();
            }
            foreach (['productCode', 'brand', 'headline', 'description', 'callToAction'] as $field) {
                if (!is_string($input[$field])) throw new RuntimeException();
            }
            if (!is_array($input['benefits']) || !array_is_list($input['benefits'])) {
                throw new RuntimeException();
            }

            // SiteBrief retains the existing product, code-point, UTF-8, NUL
            // and benefit rules. Valid text is not trimmed or normalized here.
            $brief = new SiteBrief(
                $input['productCode'], $input['brand'], $input['headline'],
                $input['description'], $input['callToAction'], $input['benefits'],
            );
            // New local rendering inputs need content beyond Unicode White_Space.
            // Do not trim valid text or change SiteBrief: historical preview
            // readers also reconstruct that object from their persisted bytes.
            foreach ([$brief->brand, $brief->headline, $brief->description,
                $brief->callToAction, ...$brief->benefits] as $text) {
                if (preg_match('/\A\p{White_Space}*\z/u', $text) !== 0) throw new RuntimeException();
            }
            $artifact = (new StaticSiteRenderer())->render($brief);
            if (!InertPreviewAudit::isSafe($artifact->html)) throw new RuntimeException();
            return $artifact;
        } catch (Throwable) {
            // Do not expose local input, validation detail or an error chain.
            throw new RuntimeException('LOCAL_RENDER_DENIED');
        }
    }

    /** json_decode otherwise accepts duplicate keys, including escaped ones. */
    private static function rejectDuplicateKeys(string $json): void
    {
        $objects = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($char === '{') $objects[] = [];
            elseif ($char === '[') $objects[] = null;
            elseif ($char === '}' || $char === ']') array_pop($objects);
            elseif ($char === '"') {
                $start = $i;
                for (++$i; $i < $length; ++$i) {
                    if ($json[$i] === '\\') { ++$i; continue; }
                    if ($json[$i] === '"') break;
                }
                $next = $i + 1;
                while ($next < $length && str_contains(" \t\r\n", $json[$next])) ++$next;
                if ($next < $length && $json[$next] === ':') {
                    $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    $level = count($objects) - 1;
                    if ($level < 0 || !is_array($objects[$level]) || isset($objects[$level][$key])) {
                        throw new RuntimeException();
                    }
                    $objects[$level][$key] = true;
                }
            }
        }
    }
}
