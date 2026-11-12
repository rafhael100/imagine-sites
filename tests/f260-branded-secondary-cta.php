<?php

declare(strict_types=1);

/**
 * F1-260: branded secondary CTA must use only typed, contrast-checked
 * palette tokens and preserve exact private source/review parity.
 * No user assets, remote content, forms, PII or publication.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unexpected F1-260 lab class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as Package;
use Imagine\Sites\Production\SyntheticPrivateSecondaryCtaParityV1 as Parity;
use Imagine\Sites\Production\SyntheticSecondaryCtaChangeEvidenceV1 as Evidence;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;

$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$checks=0;
function check260(bool $ok,string $label):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-260 '.$label);
    ++$checks;
}
function deny260(callable $cb):bool {
    try{$cb();}catch(DomainException){return true;}
    return false;
}
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        foreach(Catalog::palettes() as $code=>$palette) {
            $id=$product.'-'.$style.'-'.$code;
            $base=[$product,'Marca Fictícia Imagine',$style,$copy,
                'tenant-f260','site-f260'];
            $off=Draft::build(
                ...[...$base,'workflow-f260-'.$id.'-off',
                    'release-f260-'.$id.'-off'],
                officialBrandPalette:$palette,
                officialSecondaryCallToAction:false
            );
            $on=Draft::build(
                ...[...$base,'workflow-f260-'.$id.'-on',
                    'release-f260-'.$id.'-on'],
                officialBrandPalette:$palette,
                officialSecondaryCallToAction:true
            );
            $expected=$palette===null
                ? '.secondary-cta { background: #173e69; color: #fff; }'
                : '.secondary-cta { background: '.$palette->buttonBackground.
                    '; color: '.$palette->buttonText.'; border: 2px solid '.
                    $palette->buttonText.'; }';

            check260($off->brief->secondaryCallToAction===false &&
                $on->brief->secondaryCallToAction===true &&
                !str_contains($off->artifact->html,'secondary-action') &&
                !str_contains($off->artifact->html,'secondary-cta') &&
                str_contains($on->artifact->html,$expected) &&
                substr_count($on->artifact->html,$expected)===1,
                'opt-in only and palette-specific CSS: '.$id);
            check260($palette===null || $palette->contrast()['button']>=4.5,
                'branded button foreground/background meet AA: '.$id);
            check260(Audit::isSafe($on->artifact->html) &&
                QA::inspect($on->artifact)->passedStructuralChecks &&
                hash_equals(hash('sha256',$on->artifact->html),$on->artifact->digest) &&
                $on->reviewSummary()['publishable']===false &&
                $on->reviewSummary()['officialClientAcceptance']===false,
                'canonical nonpublishable inert HTML: '.$id);
            $beforeFiles=Package::fromPendingDraft($off);
            $afterFiles=Package::fromPendingDraft($on);
            $proof=Parity::inspect($beforeFiles,$off->brief,$afterFiles,$on->brief);
            check260($proof->stillMatches($beforeFiles,$off->brief,
                $afterFiles,$on->brief) && !$proof->canPublish() &&
                !$proof->canWriteCrm() && !$proof->canIndex(),
                'four-file before-after parity and deny-only evidence: '.$id);
            $evidence=Evidence::between($off,$on);
            check260($evidence->stillMatches($off,$on) &&
                $evidence->record['scopeCorrelationSha256']!=='',
                'pseudonymized SHA evidence still matches branded CTA: '.$id);
            $modified=$afterFiles;
            $modified['index.html']=str_replace(
                $expected,'.secondary-cta { background: #000000; color: #fff; }',
                $modified['index.html']
            );
            check260(deny260(static fn()=>Parity::inspect(
                $beforeFiles,$off->brief,$modified,$on->brief
            )),'CSS tampering denied by reviewed source digests: '.$id);
        }
    }
}
check260($checks===144,'24 product/theme/palette pairs run all six checks');
echo "$checks F1-260 branded CTA exact-palette/parity assertions passed.\n";
