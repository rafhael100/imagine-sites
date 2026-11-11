<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;

/**
 * F1-226: prove that two canonical private review files differ ONLY in
 * their SEO head text fields, including optional social-card text.
 *
 * No public URL, crawler, publisher, authenticated actor or CRM transport.
 */
final readonly class SyntheticPrivateSeoCopyDiffV1
{
    /** @var list<string> */
    public const CHANGED_FIELDS=['seoTitle','seoDescription'];

    private const TAGS=[
        '~(<meta name="description" content=")[^"]*(">)~u',
        '~(<title>)[^<]*(</title>)~u',
        '~(<meta property="og:title" content=")[^"]*(">)~u',
        '~(<meta property="og:description" content=")[^"]*(">)~u',
        '~(<meta name="twitter:title" content=")[^"]*(">)~u',
        '~(<meta name="twitter:description" content=")[^"]*(">)~u',
    ];

    private function __construct(
        public string $defaultDigest,
        public string $customDigest,
        public string $defaultReleaseRef,
        public string $customReleaseRef,
        public bool $socialEnabled,
        public array $changedFields,
    ) {}

    /** @param array<string,string> $defaultFiles
     *  @param array<string,string> $customFiles
     */
    public static function inspect(
        array $defaultFiles,
        SiteBrief $defaultBrief,
        array $customFiles,
        SiteBrief $customBrief,
    ):self {
        if ($defaultBrief->seoTitle!==null ||
            $defaultBrief->seoDescription!==null ||
            $customBrief->seoTitle===null ||
            $customBrief->seoDescription===null ||
            $defaultBrief->socialPreviewMetadata!==
                $customBrief->socialPreviewMetadata) {
            throw new DomainException('private_seo_diff_requires_default_and_custom_modes');
        }

        $otherFieldsBefore=get_object_vars($defaultBrief);
        $otherFieldsAfter=get_object_vars($customBrief);
        unset($otherFieldsBefore['seoTitle'],$otherFieldsBefore['seoDescription']);
        unset($otherFieldsAfter['seoTitle'],$otherFieldsAfter['seoDescription']);
        if ($otherFieldsBefore!==$otherFieldsAfter) {
            throw new DomainException('private_seo_diff_nonseo_field_changed');
        }

        $before=SyntheticPrivateSeoSnippetReadbackV1::inspect(
            $defaultFiles,$defaultBrief
        );
        $after=SyntheticPrivateSeoSnippetReadbackV1::inspect(
            $customFiles,$customBrief
        );
        if ($before->artifactDigest===$after->artifactDigest ||
            $before->releaseRef===$after->releaseRef ||
            $before->snippet->title===$after->snippet->title ||
            $before->snippet->description===$after->snippet->description ||
            $before->canPublish() || $before->canIndex() ||
            $after->canPublish() || $after->canIndex() ||
            $before->canSendToSeoEngine() || $after->canSendToSeoEngine()) {
            throw new DomainException('private_seo_diff_requires_distinct_denied_revisions');
        }

        // Byte-for-byte *entire-document* comparison after neutralizing
        // ONLY the six known SEO title/description slots (two without cards).
        // This also proves H1, body, styles, robots and nav remain unchanged.
        $expectedSocial=$defaultBrief->socialPreviewMetadata;
        if (self::neutralize($defaultFiles['index.html'],$expectedSocial)!==
            self::neutralize($customFiles['index.html'],$expectedSocial)) {
            throw new DomainException('private_seo_diff_unrelated_html_changed');
        }

        return new self(
            $before->artifactDigest,$after->artifactDigest,
            $before->releaseRef,$after->releaseRef,$expectedSocial,
            self::CHANGED_FIELDS
        );
    }

    private static function neutralize(string $html,bool $social):string
    {
        foreach(self::TAGS as $index=>$pattern) {
            $replacementCount=0;
            $result=preg_replace_callback(
                $pattern,
                static fn(array $hit):string=>$hit[1].'SEO_COPY_SLOT'.$hit[2],
                $html,-1,$replacementCount
            );
            $expected=$index<2 || $social ? 1:0;
            if (!is_string($result) || $replacementCount!==$expected) {
                throw new DomainException('private_seo_diff_missing_or_duplicate_head_slot');
            }
            $html=$result;
        }
        return $html;
    }

    public function canIndex():bool {return false;}
    public function canPublish():bool {return false;}
    public function canApproveForClient():bool {return false;}
    public function canWriteSeoEngine():bool {return false;}
}
