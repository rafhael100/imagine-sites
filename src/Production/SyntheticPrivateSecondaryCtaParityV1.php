<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

use DomainException;
use JsonException;

/**
 * F1-257: forensic, offline parity across two canonical PRIVATE four-file
 * review packages differing solely in the editor-owned secondary CTA flag.
 *
 * This is not authenticated identity, a human approval, a signature or a
 * license to publish, index, deliver a lead, or invoke a provider.
 */
final readonly class SyntheticPrivateSecondaryCtaParityV1
{
    private const FILES = [
        'index.html',
        'internal-review-dossier.json',
        'internal-review-draft.json',
        'release-manifest.json',
    ];

    private function __construct(
        /** @var array<string,mixed> */
        public array $record,
        public string $json,
        public string $digest,
    ) {}

    /** @param array<string,string> $beforeFiles @param array<string,string> $afterFiles */
    public static function inspect(
        array $beforeFiles,
        SiteBrief $beforeBrief,
        array $afterFiles,
        SiteBrief $afterBrief,
    ): self {
        if (array_keys($beforeFiles)!==self::FILES ||
            array_keys($afterFiles)!==self::FILES ||
            $beforeBrief->secondaryCallToAction ||
            !$afterBrief->secondaryCallToAction) {
            throw new DomainException('private_cta_parity_requires_exact_inert_packages');
        }

        // Changes to any other typed field are out of scope, even when they
        // might not change the final HTML bytes for a particular fixture.
        $before=get_object_vars($beforeBrief);
        $after=get_object_vars($afterBrief);
        unset($before['secondaryCallToAction'],$after['secondaryCallToAction']);
        $beforePalette=$beforeBrief->brandPalette?->cssRules();
        $afterPalette=$afterBrief->brandPalette?->cssRules();
        unset($before['brandPalette'],$after['brandPalette']);
        if ($before!==$after || $beforePalette!==$afterPalette) {
            throw new DomainException('private_cta_parity_noncta_editorial_drift');
        }

        $proofBefore=SyntheticPrivateDraftVerifier::verifyBytes($beforeFiles);
        $proofAfter=SyntheticPrivateDraftVerifier::verifyBytes($afterFiles);
        foreach ([$proofBefore,$proofAfter] as $proof) {
            if ($proof['reviewerDecisionStatus']!=='pending' ||
                $proof['publishable']!==false ||
                $proof['officialClientAcceptance']!==false) {
                throw new DomainException('private_cta_parity_approval_not_pending');
            }
        }
        if ($proofBefore['tenantRef']!==$proofAfter['tenantRef'] ||
            $proofBefore['siteRef']!==$proofAfter['siteRef'] ||
            $proofBefore['releaseRef']===$proofAfter['releaseRef'] ||
            hash_equals($proofBefore['artifactDigest'],$proofAfter['artifactDigest'])) {
            throw new DomainException('private_cta_parity_scope_or_release_mismatch');
        }
        $renderer=new StaticSiteRenderer();
        $beforeRendered=$renderer->render($beforeBrief);
        $afterRendered=$renderer->render($afterBrief);
        foreach ([
            [$beforeRendered,$beforeFiles,$proofBefore],
            [$afterRendered,$afterFiles,$proofAfter],
        ] as [$artifact,$files,$proof]) {
            if (!hash_equals($artifact->html,$files['index.html']) ||
                !hash_equals($artifact->digest,$proof['artifactDigest']) ||
                !hash_equals(hash('sha256',$files['index.html']),$artifact->digest) ||
                !InertPreviewAudit::isSafe($files['index.html']) ||
                !SyntheticPreviewQaReport::inspect($artifact)->passedStructuralChecks) {
                throw new DomainException('private_cta_parity_canonical_html_mismatch');
            }
        }

        // Stronger than a mere digest comparison: replace the only permitted
        // inserted CTA and its fixed CSS in the on-version. All other HTML
        // must then be byte-for-byte identical to the off-version.
        $on=$afterFiles['index.html'];
        $button='<p class="secondary-action"><a class="btn secondary-cta" href="#contato">'.
            htmlspecialchars($afterBrief->callToAction,
                ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8').'</a></p>';
        $css="    .secondary-action { margin: 16px 0 0; }\n".
            "    .secondary-cta { background: #173e69; color: #fff; }\n";
        if (substr_count($on,$button)!==1 ||
            substr_count($on,$css)!==1) {
            throw new DomainException('private_cta_parity_missing_fixed_button_or_css');
        }
        // The renderer prefixes a newline before the new paragraph.
        $neutral=preg_replace('~\n        '.preg_quote($button,'~').'~',
            '',$on,1,$removedButton);
        if (!is_string($neutral) || $removedButton!==1) {
            throw new DomainException('private_cta_parity_cta_markup_not_canonical');
        }
        $neutral=str_replace($css,'',$neutral,$removedCss);
        if ($removedCss!==1 ||
            !hash_equals($beforeFiles['index.html'],$neutral)) {
            throw new DomainException('private_cta_parity_unrelated_html_changed');
        }

        $fileHashes=[];
        foreach (['before'=>$beforeFiles,'after'=>$afterFiles] as $side=>$files) {
            foreach (self::FILES as $file) {
                $fileHashes[$side][$file]=hash('sha256',$files[$file]);
            }
        }
        try {
            $scope=json_encode([$proofBefore['tenantRef'],$proofBefore['siteRef']],
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $record=[
                'schemaVersion'=>'sites.synthetic.private-secondary-cta-parity.v1',
                'reviewState'=>'pending',
                'authority'=>'none',
                'scopeCorrelationSha256'=>hash('sha256',$scope),
                'beforeReleaseRefSha256'=>hash('sha256',$proofBefore['releaseRef']),
                'afterReleaseRefSha256'=>hash('sha256',$proofAfter['releaseRef']),
                'beforeHtmlSha256'=>$proofBefore['artifactDigest'],
                'afterHtmlSha256'=>$proofAfter['artifactDigest'],
                'changedControl'=>'secondaryCallToAction',
                'beforeEnabled'=>false,
                'afterEnabled'=>true,
                'onlyLocalContactAnchor'=>true,
                'filesSha256'=>$fileHashes,
                'clientAccepted'=>false,
                'publishable'=>false,
                'crmWriteAllowed'=>false,
                'searchIndexingAllowed'=>false,
            ];
            $json=json_encode($record,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        } catch (JsonException $e) {
            throw new DomainException('private_cta_parity_json_failed',previous:$e);
        }
        return new self($record,$json,hash('sha256',$json));
    }

    /** @param array<string,string> $beforeFiles @param array<string,string> $afterFiles */
    public function stillMatches(
        array $beforeFiles,
        SiteBrief $beforeBrief,
        array $afterFiles,
        SiteBrief $afterBrief,
    ):bool {
        try {
            $fresh=self::inspect($beforeFiles,$beforeBrief,$afterFiles,$afterBrief);
        } catch (DomainException|\InvalidArgumentException) {
            return false;
        }
        return hash_equals($this->digest,$fresh->digest) &&
            hash_equals($this->json,$fresh->json);
    }

    public function canPublish():bool {return false;}
    public function canAcceptForClient():bool {return false;}
    public function canWriteCrm():bool {return false;}
    public function canIndex():bool {return false;}
}
