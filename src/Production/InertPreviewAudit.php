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
            'section'=>true, 'h1'=>true, 'h2'=>true, 'h3'=>true, 'p'=>true,
            'ul'=>true, 'ol'=>true, 'li'=>true, 'footer'=>true,
            'details'=>true, 'summary'=>true,
        ];
        $meta = [];
        $links = [];
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
            // Native FAQ disclosure uses exactly attribute-free semantic tags.
            // Do not accept open-by-default, toggles or injected attributes.
            if (in_array($name,['details','summary'],true) &&
                !in_array($tag,['<details>','</details>',
                    '<summary>','</summary>'],true)) {
                return ['unsafe_faq_disclosure_attributes'];
            }
            if (preg_match('/\s(?:on[a-z]+|src|srcset|style|action|formaction|target|ping|download)\s*=/i', $tag)) {
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

        // F1-274: the basic bundle auditor must also deny misplaced or
        // noncanonical FAQ disclosures. The semantic DOM QA is performed
        // separately; bundle verification uses this audit directly, so a
        // recomputed SHA-256 must not bypass this structural boundary.
        $faqDetails=substr_count($html,'<details>');
        $faqSummaries=substr_count($html,'<summary>');
        if ($faqDetails>0 || $faqSummaries>0 ||
            str_contains($html,'</details>') ||
            str_contains($html,'</summary>')) {
            $faqStart='<section class="faq" id="perguntas" aria-labelledby="faq-title">';
            $sectionMatches=preg_match_all(
                '~'.preg_quote($faqStart,'~').'(.*?)</section>~s',
                $html,$faqSections
            );
            if ($faqDetails<1 || $faqDetails>6 ||
                $faqDetails!==$faqSummaries ||
                substr_count($html,'</details>')!==$faqDetails ||
                substr_count($html,'</summary>')!==$faqSummaries ||
                $sectionMatches!==1) {
                return ['noncanonical_faq_disclosure_inventory'];
            }
            $body=$faqSections[1][0];
            // F1-275: enforce the complete renderer FAQ shell, not only the
            // number of rows. Inserting extra inert divs, paragraphs or
            // headings must not become a valid rehashed release bundle.
            $prefix="\n      <div class=\"container\">\n".
                "        <h2 id=\"faq-title\">Perguntas frequentes</h2>\n".
                "        <ul>\n";
            $suffix="\n        </ul>\n      </div>\n    ";
            if (!str_starts_with($body,$prefix) ||
                !str_ends_with($body,$suffix) ||
                strlen($body)<=strlen($prefix)+strlen($suffix)) {
                return ['noncanonical_faq_section_shell'];
            }
            $rowsBytes=substr($body,strlen($prefix),
                strlen($body)-strlen($prefix)-strlen($suffix));
            $rowsLines=explode("\n",$rowsBytes);
            if (count($rowsLines)!==$faqDetails) {
                return ['noncanonical_faq_row_inventory'];
            }
            foreach($rowsLines as $line) {
                if (!preg_match(
                    '~\A          <li><details><summary>[^<>]+</summary><p>[^<>]+</p></details></li>\z~D',
                    $line
                )) {
                    return ['noncanonical_faq_row_shell'];
                }
            }
            $rows=preg_match_all(
                '~<li><details><summary>[^<>]+</summary><p>[^<>]+</p></details></li>~',
                $body,$exactRows
            );
            // Every FAQ list item must have one summary and one paragraph;
            // no loose, nested or cross-section disclosure elements.
            if ($rows!==$faqDetails ||
                substr_count($body,'<li>')!==$faqDetails ||
                substr_count($body,'</li>')!==$faqDetails ||
                substr_count($body,'<details>')!==$faqDetails ||
                substr_count($body,'<summary>')!==$faqSummaries ||
                substr_count($body,'<h3>')!==0 ||
                substr_count($html,'.faq details { width: 100%; }')!==1 ||
                substr_count($html,'.faq summary:focus-visible { outline: 3px solid #2366bc; outline-offset: 3px; }')!==1) {
                return ['noncanonical_faq_disclosure_structure'];
            }
        }

        // F1-277: if enabled, this exact skip link must precede the header
        // and be unique. No arbitrary local anchor can impersonate it.
        $skipMarkup='<a class="skip-content" href="#principal">Pular para o conteúdo</a>';
        $skipCount=substr_count($html,$skipMarkup);
        if ($skipCount>1 ||
            ($skipCount===1 && (
                !str_contains($html,">\n  ".$skipMarkup."\n  <header>") ||
                substr_count($html,'.skip-content:focus-visible { transform: none; outline: 3px solid #2366bc; outline-offset: 3px; }')!==1
            )) ||
            ($skipCount===0 && str_contains($html,'class="skip-content"'))) {
            return ['noncanonical_skip_to_content'];
        }

        // The previous five metadata tags remain mandatory and unchanged.
        // F1-206 optionally adds EXACTLY seven fixed Open Graph/Twitter
        // text-only tags. Extra robots/referrer/refresh/URLs are still denied.
        $expectedCsp = '<meta http-equiv="Content-Security-Policy" content="default-src '.
            "'none'; style-src 'unsafe-inline'; img-src 'self' data:; ".
            "base-uri 'none'; form-action 'none'; object-src 'none'\">";
        if (!in_array(count($meta),[5,12],true) ||
            $meta[0] !== '<meta charset="utf-8">' ||
            $meta[1] !== '<meta name="viewport" content="width=device-width, initial-scale=1">' ||
            !preg_match('/\A<meta name="description" content="([^"<>]*)">\z/D',
                $meta[2],$baseDescription) ||
            $meta[3] !== '<meta name="robots" content="noindex, nofollow">' ||
            $meta[4] !== $expectedCsp) {
            return ['unsafe_or_modified_metadata'];
        }
        if (count($meta)===12) {
            if ($meta[5]!=='<meta property="og:type" content="website">' ||
                $meta[6]!=='<meta property="og:locale" content="pt_BR">' ||
                !preg_match('/\A<meta property="og:title" content="([^"<>]*)">\z/D',
                    $meta[7],$socialTitle) ||
                $meta[8]!=='<meta property="og:description" content="'.
                    $baseDescription[1].'">' ||
                $meta[9]!=='<meta name="twitter:card" content="summary">' ||
                $meta[10]!=='<meta name="twitter:title" content="'.
                    $socialTitle[1].'">' ||
                $meta[11]!=='<meta name="twitter:description" content="'.
                    $baseDescription[1].'">' ||
                !str_contains($html,'<title>'.$socialTitle[1].'</title>')) {
                return ['unsafe_or_modified_social_metadata'];
            }
        }

        foreach ($links as $link) {
            if (!in_array($link, [
                '<a href="#beneficios">',
                '<a href="#sobre">',
                '<a class="btn" href="#contato">',
                '<a class="btn secondary-cta" href="#contato">',
                '<a class="skip-content" href="#principal">',
                '<a href="#solucoes">',
                '<a href="#etapas">',
                '<a href="#perguntas">',
            ], true)) {
                return ['nonlocal_navigation'];
            }
            if (!preg_match('/href="#([^"]+)"/', $link, $anchor) ||
                !str_contains($html, 'id="'.$anchor[1].'"')) {
                return ['missing_internal_anchor'];
            }
        }

        if (preg_match_all('~<style>(.*?)</style>~s', $html, $css) !== 1 ||
            preg_match('/(?:@import\b|url\s*\(|expression\s*\(|-moz-binding)/i', $css[1][0])) {
            return ['active_css_or_style_structure'];
        }

        // The previews are deliberately offline: no network targets,
        // JavaScript URL schemes or inline handlers, even in demo text.
        if (stripos($html, 'http://') !== false ||
            stripos($html, 'https://') !== false ||
            stripos($html, 'javascript:') !== false ||
            preg_match('/\bon[a-z]+\s*=/i', $html)) {
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
