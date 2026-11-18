<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

/**
 * F1-27: conservative lexical audit of the fixed, synthetic renderer output.
 * This is intentionally NOT a generic HTML sanitizer or a production approval.
 * Only the current inert Onepage/Landing fixture dialect is supported.
 */
final class InertPreviewAudit
{
    private const DESCRIPTION_META = '/\A<meta name="description" content="[^"<>]*">\z/D';

    /** @return list<string> */
    public static function issues(string $html): array
    {
        if ($html === '' || strlen($html) > 150000 ||
            !str_starts_with($html, "<!doctype html>\n<html lang=\"pt-BR\">\n<head>\n") ||
            !str_ends_with($html, '</html>')) {
            return ['unexpected_document_shell'];
        }

        // The synthetic renderer emits literal angle brackets only for tags.
        // Untrusted copy MUST remain escaped. Reject malformed/incomplete tags.
        $count = preg_match_all('~<[^<>]*>~s', $html, $found);
        if ($count === false || $count !== substr_count($html, '<') ||
            $count !== substr_count($html, '>')) {
            return ['noncanonical_markup'];
        }

        $allowed = [
            'html'=>true, 'head'=>true, 'meta'=>true, 'title'=>true,
            'style'=>true, 'body'=>true, 'header'=>true, 'div'=>true,
            'span'=>true, 'nav'=>true, 'a'=>true, 'main'=>true,
            'section'=>true, 'h1'=>true, 'h2'=>true, 'p'=>true,
            'ul'=>true, 'li'=>true, 'footer'=>true,
        ];
        $meta = [];
        $links = [];
        $activeTags = [];
        $h1Count = 0;
        $styleCount = 0;
        $bodyCount = 0;
        foreach ($found[0] as $tag) {
            if ($tag === '<!doctype html>') continue;
            if (!preg_match('~\A<(\/?)([a-z][a-z0-9-]*)\b[^<>]*>\z~iD', $tag, $match)) {
                return ['unsupported_markup'];
            }
            $name = strtolower($match[2]);
            if (!isset($allowed[$name])) return ['active_or_unknown_element'];
            // Only this exact metadata form carries escaped user copy in an
            // attribute. Its value is text, not another attribute or a URL
            // target. Keep the original tag for the metadata checks below.
            $auditTag = preg_match(self::DESCRIPTION_META, $tag) === 1
                ? '<meta name="description" content="">' : $tag;
            $activeTags[] = $auditTag;
            if (preg_match('/\s(?:on[a-z]+|src|srcset|style|action|formaction|target|ping|download)\s*=/i', $auditTag)) {
                return ['active_or_external_attribute'];
            }
            if ($match[1] !== '') continue;
            if ($name === 'meta') $meta[] = $tag;
            if ($name === 'a') $links[] = $tag;
            if ($name === 'h1') ++$h1Count;
            if ($name === 'style') ++$styleCount;
            if ($name === 'body') {
                ++$bodyCount;
                if (!in_array($tag, [
                    '<body data-site-product="onepage">',
                    '<body data-site-product="landing-page">',
                ], true)) return ['unsupported_product_body'];
            }
        }
        if ($bodyCount !== 1 || $styleCount !== 1 || $h1Count !== 1 ||
            substr_count($html, '<h1 id="hero-title">') !== 1 ||
            substr_count($html, '<main id="principal">') !== 1 ||
            substr_count($html, '<section class="contact" id="contato"') !== 1) {
            return ['invalid_preview_structure'];
        }

        // Require exactly the five metadata tags of the safe renderer, in order.
        // In particular, reject a second robots tag and any meta refresh.
        $expectedCsp = '<meta http-equiv="Content-Security-Policy" content="default-src '.
            "'none'; style-src 'unsafe-inline'; img-src 'self' data:; ".
            "base-uri 'none'; form-action 'none'; object-src 'none'\">";
        if (count($meta) !== 5 ||
            $meta[0] !== '<meta charset="utf-8">' ||
            $meta[1] !== '<meta name="viewport" content="width=device-width, initial-scale=1">' ||
            !preg_match(self::DESCRIPTION_META, $meta[2]) ||
            $meta[3] !== '<meta name="robots" content="noindex, nofollow">' ||
            $meta[4] !== $expectedCsp) {
            return ['unsafe_or_modified_metadata'];
        }

        foreach ($links as $link) {
            if (!in_array($link, [
                '<a href="#beneficios">',
                '<a href="#sobre">',
                '<a class="btn" href="#contato">',
            ], true)) {
                return ['nonlocal_navigation'];
            }
            if (!preg_match('/href="#([^"]+)"/', $link, $anchor) ||
                !str_contains($html, 'id="'.$anchor[1].'"')) {
                return ['missing_internal_anchor'];
            }
        }

        // CSS resolves escapes inside identifiers before interpreting url()
        // and at-rules. The fixed renderer dialect needs no CSS escapes, so
        // refuse them in styles rather than interpreting a broader language.
        // image-set() and its alias also accept URL strings without url().
        // Literal copy in text and description metadata is unaffected.
        if (preg_match_all('~<style>(.*?)</style>~s', $html, $css) !== 1 ||
            str_contains($css[1][0], '\\') ||
            preg_match('/(?:@import\b|(?:url|(?:-webkit-)?image-set|expression)\s*\(|-moz-binding)/i', $css[1][0])) {
            return ['active_css_or_style_structure'];
        }

        // Inspect markup and CSS, not escaped text between the accepted tags.
        // Literal URLs or handler-like words in copy create no network target
        // or executable attribute. This audit copy never replaces stored HTML
        // and is never used to compute an artifact or package digest.
        $activeContent = implode("\n", $activeTags)."\n".$css[1][0];
        if (stripos($activeContent, 'http://') !== false ||
            stripos($activeContent, 'https://') !== false ||
            stripos($activeContent, 'javascript:') !== false ||
            preg_match('/\bon[a-z]+\s*=/i', $activeContent)) {
            return ['remote_or_active_content'];
        }

        if (!str_contains($html,
            'Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.')) {
            return ['missing_inert_preview_disclaimer'];
        }
        return [];
    }

    public static function isSafe(string $html): bool
    {
        return self::issues($html) === [];
    }
}
