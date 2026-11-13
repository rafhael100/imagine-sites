<?php

declare(strict_types=1);

/**
 * F1-274: a recomputed HTML hash must NOT allow a misplaced or malformed
 * native disclosure through the basic release bundle audit.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe F1-274 class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticReleaseBundle as Bundle;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as PrivateFiles;
use Imagine\Sites\Domain\ReleaseManifest as Manifest;

$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$checks=0;
function check274(bool $ok,string $name):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-274 '.$name);
    ++$checks;
}
function denied274(string $html):bool {
    $artifact=new ReleaseArtifact($html);
    $manifest=new Manifest('tenant-f274','site-f274',
        'release-f274-rehashed',$artifact->digest);
    try {
        new Bundle($manifest,$artifact);
    } catch (DomainException) {return true;}
    return false;
}
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $draft=Draft::build(
            $product,'Marca Fictícia Imagine',$style,$copy,
            'tenant-f274','site-f274',
            'workflow-f274-'.$product.'-'.$style,
            'release-f274-'.$product.'-'.$style,
            officialFaqDisclosure:true
        );
        $html=$draft->artifact->html;
        $files=PrivateFiles::fromPendingDraft($draft);
        check274(Audit::isSafe($html) &&
            Bundle::verifyFiles($draft->bundle->files,$draft->manifest) &&
            $files['index.html']===$html &&
            substr_count($html,'<details>')===2,
            'valid private disclosure release remains packageable');

        check274(preg_match(
            '~<li><details><summary>[^<>]+</summary><p>[^<>]+</p></details></li>~',
            $html,$row
        )===1,'the exact FAQ row exists');
        $moved=str_replace($row[0],'',$html);
        $moved=str_replace('</main>','</main>'.$row[0],$moved);
        $mutations=[
            'outside FAQ, exact row'=>$moved,
            'stray summary'=>str_replace(
                '</main>','<summary>Stray</summary></main>',$html),
            'stray details'=>str_replace(
                '</main>','<details></details></main>',$html),
            'FAQ details marked open'=>str_replace(
                '<details>','<details open>',$html),
            'FAQ summary adds attributes'=>str_replace(
                '<summary>','<summary role="button">',$html),
            'FAQ uses a noncanonical wrapper'=>str_replace(
                '<li><details><summary>','<li><details><div><summary>',$html),
            'FAQ summary contains extra markup'=>str_replace(
                '<summary>','<summary><span>Extra</span>',$html),
            'FAQ answer contains an extra paragraph'=>str_replace(
                '</summary><p>','</summary><p></p><p>',$html),
            'FAQ loses a details end'=>str_replace(
                '</details>','',$html),
            'FAQ loses its focus style'=>str_replace(
                '.faq summary:focus-visible { outline: 3px solid #2366bc; outline-offset: 3px; }',
                '.faq summary:focus-visible { outline: none; }',$html),
            'FAQ list uses heading instead of disclosure'=>str_replace(
                '<li><details><summary>','<li><h3>',$html),
            'FAQ has unexpected non-disclosure list item'=>str_replace(
                '        </ul>',"          <li><h3>Additional FAQ</h3></li>\n        </ul>",$html),
        ];
        foreach($mutations as $label=>$modified) {
            check274($modified!==$html &&
                !Audit::isSafe($modified) &&
                denied274($modified),
                'rehashed package rejects '.$label.' for '.$product.'-'.$style);
        }
    }
}
echo "$checks F1-274 malformed FAQ bundle negative assertions passed.\n";
