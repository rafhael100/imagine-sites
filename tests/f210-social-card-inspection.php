<?php

declare(strict_types=1);

/** F1-210: text-only social card is derived from rendered, pinned OG bytes. */
spl_autoload_register(static function (string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unexpected synthetic class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticSocialCardInspectionV1 as Inspector;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SiteBrief;

$checked=0;
function check210(bool $ok,string $case):void {
    global $checked;
    if(!$ok)throw new RuntimeException('F1-210 '.$case);
    ++$checked;
}
function rejected210(callable $action):bool {
    try{$action();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$examples=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach(['services_process_faq','faq_process_services'] as $order) {
            foreach(['default','forest'] as $palette) {
                $choice=Catalog::choose($product,$preset,$order,'off',$palette);
                $args=[
                    $product,'Marca Fictícia Imagine',$preset,$copy,
                    'tenant-f210','site-f210',
                    'workflow-f210-'.$product.'-'.$preset.'-'.$order.'-'.$palette,
                    'release-f210-'.$product.'-'.$preset.'-'.$order.'-'.$palette,
                    $choice['order'],false,$choice['palette'],
                ];
                $enabled=Draft::build(...[...$args,true]);
                $disabled=Draft::build(...[...$args,false]);
                $social=Inspector::inspect($enabled->brief,$enabled->artifact);
                ++$examples;
                check210($social->title===$enabled->brief->headline.' | '.$enabled->brief->brand &&
                    $social->description===$enabled->brief->description &&
                    $social->brand===$enabled->brief->brand &&
                    $social->artifactDigest===$enabled->artifact->digest,
                    'social card describes exact canonical headline/brand/description');
                check210(!$social->canPublish() &&
                    !$social->canFetchExternalImage() &&
                    !$social->canShareExternally() &&
                    !$social->canAcceptClientApproval(),
                    'a display-only card cannot grant real operations');
                check210(rejected210(static fn()=>Inspector::inspect(
                    $disabled->brief,$disabled->artifact
                )),'disabled metadata never creates a phantom social preview');

                $tampered=new ReleaseArtifact(
                    str_replace('og:description','og:image',
                        $enabled->artifact->html)
                );
                check210($tampered->digest!==$enabled->artifact->digest &&
                    rejected210(static fn()=>Inspector::inspect(
                        $enabled->brief,$tampered
                    )),'injected image meta cannot masquerade as pinned artifact');

                $switched=new SiteBrief(
                    $enabled->brief->productCode,$enabled->brief->brand,
                    'Título falso não autorizado',$enabled->brief->description,
                    $enabled->brief->callToAction,$enabled->brief->benefits,
                    $enabled->brief->stylePreset,$enabled->brief->faq,
                    $enabled->brief->highlights,$enabled->brief->processSteps,
                    $enabled->brief->sectionOrder,$enabled->brief->sectionNavigation,
                    $enabled->brief->brandPalette,true
                );
                check210(rejected210(static fn()=>Inspector::inspect(
                    $switched,$enabled->artifact
                )),'headline identity must match rendered artifact');
                check210($social->artifactDigest!==$disabled->artifact->digest,
                    'enablement creates independent internal-review identity');
            }
        }
    }
}
check210($examples===24,'24 canonical product/preset/order/palette combinations');

echo "$checked F1-210 canonical social metadata inspections passed; ".
     "text-only preview, no image, URL, network, client acceptance or deployment.\n";
