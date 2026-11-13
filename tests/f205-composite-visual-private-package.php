<?php

declare(strict_types=1);

/** F1-205: no client data, external network, real files or publication. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative))
        throw new RuntimeException('Unexpected fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture as Design;
use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verify;

$checks=0;
function check205(bool $condition,string $label):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-205 '.$label);
    ++$checks;
}
function denied205(callable $fn):bool {
    try {$fn();}catch(DomainException|InvalidArgumentException|TypeError){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$digests=[];
$original=0;
$changed=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach(Catalog::sectionOrders() as $orderCode=>$order) {
            foreach($product==='onepage'?['off','on']:['off'] as $nav) {
                foreach(Catalog::palettes() as $paletteCode=>$palette) {
                    $fixed=Design::build(
                        $copy,$product,$preset,$orderCode,$nav,$paletteCode
                    );
                    $isOriginal=Catalog::isOriginal(
                        $preset,$orderCode,$nav,$paletteCode
                    );
                    $files=$fixed->privateFiles();
                    $proof=Verify::verifyBytes($files);
                    check205($fixed->changed===!$isOriginal &&
                        $proof['releaseRef']==='release-f205-'.$product.'-'.
                            $preset.'-'.$orderCode.'-'.$paletteCode.'-'.$nav &&
                        $proof['tenantRef']==='tenant-f205' &&
                        $proof['siteRef']==='site-f205',
                        'canonical name and real-changed flag agree with catalog');
                    check205($proof['artifactDigest']===$fixed->draft->artifact->digest &&
                        hash('sha256',$files['index.html'])===$proof['artifactDigest'] &&
                        $proof['bundleDigest']===$fixed->draft->bundle->bundleDigest,
                        'exact draft bytes and four-file contents are independently verified');
                    check205($proof['workflowRevision']===2 &&
                        $proof['reviewerDecisionStatus']==='pending' &&
                        $proof['publishable']===false &&
                        $proof['officialClientAcceptance']===false &&
                        $proof['externalPortalAuthorityRequired']===true,
                        'never create a real client approval or publishable review');
                    check205(array_keys($files)===[
                        'index.html','internal-review-dossier.json',
                        'internal-review-draft.json','release-manifest.json'
                    ] && !str_contains($files['index.html'],'<script') &&
                        !str_contains($files['index.html'],'<form') &&
                        str_contains($files['index.html'],'noindex, nofollow'),
                        'exact four inert files with no lead intake or active scripts');
                    check205($fixed->changed ?
                        $proof['artifactDigest']!==$fixed->originalDigest :
                        $proof['artifactDigest']===$fixed->originalDigest,
                        'no spurious change and no reuse of prior approval');
                    $key=implode('|',[$product,$preset,$orderCode,$paletteCode,$nav]);
                    $digests[$key]=$proof['artifactDigest'];
                    $isOriginal?++$original:++$changed;
                }
            }
        }
    }
}
check205(count($digests)===216 && count(array_unique($digests))===216,
    'all 216 composite visual combinations produce distinct checked HTML');
check205($original===2 && $changed===214,
    'exactly two product baselines, all 214 other designs reopen review');

$representative=Design::build($copy,'onepage','warm',
    'faq_process_services','on','forest');
$files=$representative->privateFiles();
check205($files===$representative->privateFiles(),
    'same fixed design always serializes to identical immutable bytes');
foreach(['index.html','internal-review-draft.json','internal-review-dossier.json',
         'release-manifest.json'] as $file) {
    $tampered=$files;
    $tampered[$file].="\n";
    check205(denied205(static fn()=>Verify::verifyBytes($tampered)),
        'modifying any file invalidates the canonical readback: '.$file);
}
foreach([
    ['blog','classic','services_process_faq','off','default'],
    ['onepage','custom','services_process_faq','off','default'],
    ['onepage','classic','unknown','off','default'],
    ['onepage','classic','services_process_faq','yes','default'],
    ['onepage','classic','services_process_faq','off','#004499'],
    ['landing_page','modern','faq_process_services','on','ocean'],
] as $bad) {
    check205(denied205(static fn()=>Design::build($copy,...$bad)),
        'unrecognized product/theme/section/nav/color cannot be packaged');
}
$invalidCopy=$copy;
$invalidCopy['schemaVersion']='sites.ai-copy.v5';
check205(denied205(static fn()=>Design::build(
    $invalidCopy,'onepage','classic','services_process_faq','off','default'
)), 'never accept a new AI format without explicit review');

echo "$checks F1-205 composite visual private package tests passed: ".
    "$original baselines, $changed new private designs; no real delivery.\n";
