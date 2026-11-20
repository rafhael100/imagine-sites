<?php

declare(strict_types=1);

namespace App\Integration;

use Imagine\Sites\Production\BriefCandidateJson;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Private, deterministic Landing Page editorial preview derived only from an
 * independently checked, current Portal-approved briefing candidate.
 *
 * This does not authenticate Portal, accept orders, record a review, approve
 * content, or create publishing authority. No public controller or writer uses
 * this candidate. The HTML is confidential until an authorized review exists.
 */
final readonly class PortalLandingPageEditorialDraftCandidate
{
    private const DOMAIN = "SITES_LANDING_PRIVATE_EDITORIAL_DRAFT_V1";
    private const CONTENT_SCHEMA = 'portal.landing-content.candidate.v1';

    public function __construct(
        private PortalLandingPageApprovedBriefReadbackCandidate $approved =
            new PortalLandingPageApprovedBriefReadbackCandidate(),
    ) {}

    /**
     * All expected selectors and the approval digest must come from independently
     * authorized custody, never from an HTTP body, Site draft or preview claim.
     *
     * @return array{artifactBytes:string,artifactSha256:string,reviewBytes:string,reviewSha256:string}
     */
    public function prepareForInternalReview(
        string $tenantRef, string $orderRef, string $siteRef,
        string $contractRef, int $contractRevision, int $sourceVersion,
        string $approvalRef, int $approvalEpoch, string $expectedSourceSha256,
    ): array {
        try {
            $head = $this->approved->assertCurrent(
                $tenantRef, $orderRef, $siteRef, $contractRef, $contractRevision,
                $sourceVersion, $approvalRef, $approvalEpoch, $expectedSourceSha256,
            );
            $fields = self::validateContent($head->sourceBytes());
            $artifact = self::html($fields);
            if (strlen($artifact) > 200000) throw new RuntimeException();
            $artifactSha = hash('sha256', $artifact);

            // Recheck the externally held approval after rendering. This only
            // detects observed drift, NOT an atomic distributed authorization,
            // later revocation or proof of the approving actor's identity.
            $current = $this->approved->assertCurrent(
                $tenantRef, $orderRef, $siteRef, $contractRef, $contractRevision,
                $sourceVersion, $approvalRef, $approvalEpoch, $expectedSourceSha256,
            );
            if (!hash_equals($head->sourceSha256, $current->sourceSha256) ||
                !hash_equals($head->sourceBytes(), $current->sourceBytes())) {
                throw new RuntimeException();
            }

            $reviewBytes = json_encode([
                'schemaVersion' => 'sites.landing-private-review.candidate.v1',
                'state' => 'pending_independent_internal_review',
                'templateVersion' => 'imagine-landing-inert-v1',
                'tenantRef' => $tenantRef,
                'orderRef' => $orderRef,
                'siteRef' => $siteRef,
                'contractRef' => $contractRef,
                'contractRevision' => $contractRevision,
                'productCode' => 'landing_page',
                'sourceVersion' => $sourceVersion,
                'sourceSha256' => $head->sourceSha256,
                'approvalRef' => $approvalRef,
                'approvalEpoch' => $approvalEpoch,
                'artifactSha256' => $artifactSha,
                'artifactSizeBytes' => strlen($artifact),
                'portalActorAuthenticated' => false,
                'internalReviewApproved' => false,
                'clientAccepted' => false,
                'canPublish' => false,
                'canAcceptRealOrders' => false,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            if (strlen($reviewBytes) > 4096) throw new RuntimeException();

            return [
                'artifactBytes' => $artifact,
                'artifactSha256' => $artifactSha,
                'reviewBytes' => $reviewBytes,
                'reviewSha256' => hash('sha256', self::DOMAIN."\0".$reviewBytes),
            ];
        } catch (Throwable) {
            // No private fields, content, provider exception or tenant selectors.
            throw new RuntimeException('SITES_LANDING_EDITORIAL_DRAFT_DENIED');
        }
    }

    /** @return array{headline:string,subheadline:string,benefits:array<int,string>,ctaLabel:string} */
    private static function validateContent(string $raw): array
    {
        $data = BriefCandidateJson::decode($raw, 150000);
        BriefCandidateJson::keys(
            $data, ['schemaVersion', 'headline', 'subheadline', 'benefits', 'ctaLabel'],
        );
        if ($data->schemaVersion !== self::CONTENT_SCHEMA ||
            !is_array($data->benefits) || count($data->benefits) < 1 ||
            count($data->benefits) > 5 || !array_is_list($data->benefits)) {
            throw new RuntimeException();
        }
        self::safeText($data->headline, 5, 100);
        self::safeText($data->subheadline, 10, 320);
        self::safeText($data->ctaLabel, 2, 40);
        foreach ($data->benefits as $benefit) self::safeText($benefit, 3, 120);
        return [
            'headline' => $data->headline,
            'subheadline' => $data->subheadline,
            'benefits' => $data->benefits,
            'ctaLabel' => $data->ctaLabel,
        ];
    }

    private static function safeText(mixed $text, int $min, int $max): void
    {
        if (!is_string($text) || $text !== trim($text) ||
            mb_strlen($text, 'UTF-8') < $min || mb_strlen($text, 'UTF-8') > $max ||
            preg_match('/[\x00-\x1F\x7F]/u', $text) !== 0) {
            throw new RuntimeException();
        }
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Only inert, locally rendered HTML. No URL, form, script, JS or remote asset. */
    private static function html(array $fields): string
    {
        $benefits = '';
        foreach ($fields['benefits'] as $benefit) {
            $benefits .= '<li>'.self::esc($benefit).'</li>';
        }
        return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8">'.
            '<meta name="viewport" content="width=device-width,initial-scale=1">'.
            '<meta name="robots" content="noindex,nofollow,noarchive">'.
            '<title>Prévia interna · Imagine</title>'.
            '<style>'.
            '*{box-sizing:border-box}body{margin:0;background:#fffaf4;color:#292524;font-family:Arial,sans-serif;line-height:1.6}'.
            'main{max-width:880px;margin:0 auto;padding:40px 24px 64px}'.
            'header{font-weight:800;letter-spacing:.04em;color:#7c3e00}'.
            '.banner{padding:12px 16px;border:2px solid #f9941d;border-radius:8px;margin:24px 0;color:#6b3900}'.
            'h1{font-size:clamp(2rem,5vw,3rem);line-height:1.16;margin:32px 0 16px}'.
            'p{font-size:1.125rem;max-width:65ch}ul{padding-left:24px}li{margin:12px 0}'.
            '.cta{display:inline-block;padding:14px 24px;background:#f9941d;color:#292524;font-weight:700;border-radius:8px;opacity:.65}'.
            '.note{font-size:.875rem;color:#57534e;margin-top:32px}'.
            '</style></head><body><main><header>IMAGINE</header>'.
            '<div class="banner" role="status">Prévia interna — sem publicação ou aceite</div>'.
            '<h1>'.self::esc($fields['headline']).'</h1>'.
            '<p>'.self::esc($fields['subheadline']).'</p>'.
            '<ul>'.$benefits.'</ul>'.
            '<span class="cta" aria-disabled="true">'.self::esc($fields['ctaLabel']).'</span>'.
            '<p class="note">Rascunho editorial para revisão independente. Nenhuma ação está habilitada.</p>'.
            '</main></body></html>';
    }
}
