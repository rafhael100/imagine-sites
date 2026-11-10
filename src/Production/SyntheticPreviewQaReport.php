<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * F1-39: structural, offline checks on the FIXED synthetic HTML template.
 * Not a WCAG certificate, human editorial approval or release authorization.
 */
final readonly class SyntheticPreviewQaReport
{
    /** @param array<string,bool> $checks */
    private function __construct(
        public array $checks,
        public bool $passedStructuralChecks,
    ) {}

    public static function inspect(ReleaseArtifact $artifact): self
    {
        $html=$artifact->html;
        $checks=[
            'inert_markup'=>InertPreviewAudit::isSafe($html),
            'sha256_consistent'=>hash_equals(hash('sha256',$html),$artifact->digest),
            'lang_and_title'=>false,
            'main_and_heading'=>false,
            'navigation_anchors'=>false,
            'section_heading_labels'=>false,
            'link_accessible_names'=>false,
            'mobile_and_noindex'=>false,
            'no_executable_elements'=>false,
        ];
        $prior=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument('1.0','UTF-8');
            if (!$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) {
                return self::make($checks);
            }
            $xpath=new DOMXPath($dom);
            $lang=$xpath->query('//html[@lang="pt-BR"]');
            $title=$xpath->query('//head/title');
            $checks['lang_and_title']=$lang !== false && $lang->length===1 &&
                $title !== false && $title->length===1 &&
                trim($title->item(0)?->textContent??'')!=='';

            $main=$xpath->query('//main[@id="principal"]');
            $h1=$xpath->query('//h1');
            $insideMain=$xpath->query('//main[@id="principal"]//h1');
            $checks['main_and_heading']=$main !== false && $main->length===1 &&
                $h1 !== false && $h1->length===1 &&
                $insideMain !== false && $insideMain->length===1;

            $allIds=[];
            $ids=$xpath->query('//*[@id]');
            if ($ids===false) return self::make($checks);
            $idsUnique=true;
            foreach ($ids as $element) {
                if (!$element instanceof DOMElement) continue;
                $id=$element->getAttribute('id');
                if ($id==='' || isset($allIds[$id])) $idsUnique=false;
                $allIds[$id]=true;
            }
            $links=$xpath->query('//a[@href]');
            $nav=$xpath->query('//nav');
            $linksOk=$links!==false && $nav!==false;
            if ($linksOk) {
                foreach ($links as $link) {
                    if (!$link instanceof DOMElement) continue;
                    $href=$link->getAttribute('href');
                    $anchor=str_starts_with($href,'#')?substr($href,1):'';
                    if ($anchor==='' || !isset($allIds[$anchor])) $linksOk=false;
                }
                foreach ($nav as $item) {
                    if ($item instanceof DOMElement &&
                        trim($item->getAttribute('aria-label'))==='') $linksOk=false;
                }
            }
            $checks['navigation_anchors']=$linksOk && $idsUnique && $links->length>0;

            // F1-129: a section's aria-labelledby must reference a
            // nonempty h1/h2 INSIDE that same section. Existing QA
            // allowed labels pointing to headings in another section.
            $sections=$xpath->query('//main[@id="principal"]//section');
            $sectionLabelsOk=$sections!==false && $sections->length>=2;
            if ($sectionLabelsOk) {
                foreach ($sections as $section) {
                    if (!$section instanceof DOMElement) {
                        $sectionLabelsOk=false;
                        break;
                    }
                    $label=$section->getAttribute('aria-labelledby');
                    if (!preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D',$label)) {
                        $sectionLabelsOk=false;
                        break;
                    }
                    $headings=$xpath->query('.//h1[@id] | .//h2[@id]',$section);
                    $matches=0;
                    if ($headings===false) {
                        $sectionLabelsOk=false;
                        break;
                    }
                    foreach ($headings as $heading) {
                        if ($heading instanceof DOMElement &&
                            $heading->getAttribute('id')===$label &&
                            trim($heading->textContent)!=='') ++$matches;
                    }
                    if ($matches!==1) {
                        $sectionLabelsOk=false;
                        break;
                    }
                }
            }
            $checks['section_heading_labels']=$sectionLabelsOk;

            // Every navigational/CTA link in this inert template needs
            // a readable name, not merely a valid target fragment.
            $linkNamesOk=$links!==false && $links->length>0;
            if ($linkNamesOk) {
                foreach ($links as $link) {
                    if (!$link instanceof DOMElement ||
                        (trim($link->textContent)==='' &&
                         trim($link->getAttribute('aria-label'))==='')) {
                        $linkNamesOk=false;
                        break;
                    }
                }
            }
            $checks['link_accessible_names']=$linkNamesOk;

            $viewport=$xpath->query('//meta[@name="viewport" and @content="width=device-width, initial-scale=1"]');
            $robots=$xpath->query('//meta[@name="robots" and @content="noindex, nofollow"]');
            $checks['mobile_and_noindex']=$viewport!==false && $viewport->length===1 &&
                $robots!==false && $robots->length===1;

            $active=$xpath->query('//script | //form | //iframe | //object | //embed | //svg');
            $checks['no_executable_elements']=$active!==false && $active->length===0;
            return self::make($checks);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
    }

    /** @param array<string,bool> $checks */
    private static function make(array $checks): self
    {
        return new self($checks,!in_array(false,$checks,true));
    }
}
