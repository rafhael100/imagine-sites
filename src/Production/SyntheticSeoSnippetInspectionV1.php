<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DOMDocument;
use DOMXPath;
use DomainException;

/**
 * F1-215: deterministic, read-only, text-only SEO snippet review.
 *
 * The static artifact remains noindex: this inspection is NOT a ranking
 * prediction, website crawl, canonical-URL check, release approval or
 * authorization to place any page on a search engine.
 */
final readonly class SyntheticSeoSnippetInspectionV1
{
    public const TITLE_PREVIEW_LIMIT = 60;
    public const DESCRIPTION_PREVIEW_LIMIT = 160;

    private function __construct(
        public string $title,
        public string $description,
        public string $headline,
        public int $titleCharacters,
        public int $descriptionCharacters,
        public string $titlePreview,
        public string $descriptionPreview,
        /** @var list<string> */
        public array $advisories,
        public string $artifactDigest,
    ) {}

    public static function inspect(
        SiteBrief $brief,
        ReleaseArtifact $artifact,
    ): self {
        if (!hash_equals(hash('sha256', $artifact->html), $artifact->digest)) {
            throw new DomainException('seo_inspection_artifact_digest_invalid');
        }
        $expected=(new StaticSiteRenderer())->render($brief);
        if (!hash_equals($expected->digest,$artifact->digest) ||
            !hash_equals($expected->html,$artifact->html) ||
            !InertPreviewAudit::isSafe($artifact->html)) {
            throw new DomainException('seo_inspection_requires_canonical_inert_html');
        }

        $previous=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument('1.0','UTF-8');
            if (!$dom->loadHTML(
                $artifact->html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING
            )) {
                throw new DomainException('seo_inspection_invalid_document');
            }
            $xp=new DOMXPath($dom);
            $htmlNodes=$xp->query('//html[@lang="pt-BR"]');
            $titleNodes=$xp->query('/html/head/title');
            $descriptionNodes=$xp->query('/html/head/meta[@name="description"]');
            $robotsNodes=$xp->query('/html/head/meta[@name="robots"]');
            $headingNodes=$xp->query('/html/body/main//h1');
            $canonicalNodes=$xp->query('/html/head/link[@rel="canonical"]');
            if ($htmlNodes===false || $htmlNodes->length!==1 ||
                $titleNodes===false || $titleNodes->length!==1 ||
                $descriptionNodes===false || $descriptionNodes->length!==1 ||
                $robotsNodes===false || $robotsNodes->length!==1 ||
                $headingNodes===false || $headingNodes->length!==1 ||
                $canonicalNodes===false || $canonicalNodes->length!==0) {
                throw new DomainException('seo_inspection_required_head_markup_missing');
            }

            $title=$titleNodes->item(0)->textContent;
            $description=$descriptionNodes->item(0)->getAttribute('content');
            $headline=$headingNodes->item(0)->textContent;
            if ($title!==($brief->seoTitle ?? ($brief->headline.' | '.$brief->brand)) ||
                $description!==($brief->seoDescription ?? $brief->description) ||
                $headline!==$brief->headline ||
                $robotsNodes->item(0)->getAttribute('content')!=='noindex, nofollow') {
                throw new DomainException('seo_inspection_pinned_copy_or_noindex_mismatch');
            }
            if ($brief->socialPreviewMetadata) {
                // Do not confuse OG/Twitter cards with searchable snippets.
                // Both must still agree on the exact artifact and editorial text.
                $card=SyntheticSocialCardInspectionV1::inspect($brief,$artifact);
                if ($card->title!==$title || $card->description!==$description ||
                    $card->artifactDigest!==$artifact->digest) {
                    throw new DomainException('seo_inspection_social_card_drift');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $titleLength=mb_strlen($title,'UTF-8');
        $descriptionLength=mb_strlen($description,'UTF-8');
        $advisories=[];
        if ($titleLength<30) $advisories[]='TITLE_SHORT';
        if ($titleLength>self::TITLE_PREVIEW_LIMIT) $advisories[]='TITLE_MAY_TRUNCATE';
        if ($descriptionLength<70) $advisories[]='DESCRIPTION_SHORT';
        if ($descriptionLength>self::DESCRIPTION_PREVIEW_LIMIT) {
            $advisories[]='DESCRIPTION_MAY_TRUNCATE';
        }

        return new self(
            $title,$description,$headline,$titleLength,$descriptionLength,
            self::trimForPreview($title,self::TITLE_PREVIEW_LIMIT),
            self::trimForPreview($description,self::DESCRIPTION_PREVIEW_LIMIT),
            $advisories,$artifact->digest,
        );
    }

    private static function trimForPreview(string $value,int $max):string
    {
        if (mb_strlen($value,'UTF-8')<=$max)return $value;
        return rtrim(mb_substr($value,0,$max-1,'UTF-8')).'…';
    }

    public function canPublish():bool {return false;}
    public function canIndexOnSearchEngines():bool {return false;}
    public function canWriteSeoEngine():bool {return false;}
    public function canFetchExternalPages():bool {return false;}
    public function canApproveForClient():bool {return false;}
}
