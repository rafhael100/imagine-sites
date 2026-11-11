<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DOMDocument;
use DOMXPath;
use DomainException;

/**
 * F1-210: independent, read-only inspection of the exact social metadata
 * bytes. Exposes a TEXT-ONLY preview, not a social network embed.
 *
 * No URLs, images, remote requests, client identity or publication grants.
 */
final readonly class SyntheticSocialCardInspectionV1
{
    private function __construct(
        public string $title,
        public string $description,
        public string $brand,
        public string $artifactDigest,
    ) {}

    public static function inspect(
        SiteBrief $brief,
        ReleaseArtifact $artifact
    ):self {
        if (!$brief->socialPreviewMetadata ||
            !hash_equals(hash('sha256',$artifact->html),$artifact->digest)) {
            throw new DomainException('social_card_requires_pinned_enabled_artifact');
        }
        $original=(new StaticSiteRenderer())->render($brief);
        if (!hash_equals($original->digest,$artifact->digest) ||
            !hash_equals($original->html,$artifact->html) ||
            !InertPreviewAudit::isSafe($artifact->html)) {
            throw new DomainException('social_card_rendered_bytes_not_canonical');
        }

        $previous=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument('1.0','UTF-8');
            if (!$dom->loadHTML($artifact->html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) {
                throw new DomainException('social_card_invalid_html');
            }
            $nodes=(new DOMXPath($dom))->query('//head/meta');
            if ($nodes===false || $nodes->length!==12) {
                throw new DomainException('social_card_requires_exact_metadata_count');
            }
            $found=[];
            foreach ($nodes as $meta) {
                $property=$meta->getAttribute('property');
                $name=$meta->getAttribute('name');
                if ($property==='') {
                    if (!str_starts_with($name,'twitter:'))continue;
                    $key=$name;
                } else {
                    if ($name!=='' || !str_starts_with($property,'og:')) {
                        throw new DomainException('social_card_mixed_metadata_denied');
                    }
                    $key=$property;
                }
                if (isset($found[$key])) {
                    throw new DomainException('social_card_duplicate_tag');
                }
                $found[$key]=$meta->getAttribute('content');
            }
        }finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $title=$brief->headline.' | '.$brief->brand;
        $expected=[
            'og:type'=>'website',
            'og:locale'=>'pt_BR',
            'og:title'=>$title,
            'og:description'=>$brief->description,
            'twitter:card'=>'summary',
            'twitter:title'=>$title,
            'twitter:description'=>$brief->description,
        ];
        // Exact keys, values and count: never accept an injected website,
        // canonical URL, large image, phishing link or arbitrary metadata.
        if (count($found)!==count($expected)) {
            throw new DomainException('social_card_unknown_tag_denied');
        }
        foreach ($expected as $key=>$value) {
            if (!array_key_exists($key,$found) ||
                !hash_equals($value,$found[$key])) {
                throw new DomainException('social_card_metadata_drift_denied');
            }
        }

        return new self($title,$brief->description,$brief->brand,$artifact->digest);
    }

    public function canPublish():bool {return false;}
    public function canFetchExternalImage():bool {return false;}
    public function canShareExternally():bool {return false;}
    public function canAcceptClientApproval():bool {return false;}
}
