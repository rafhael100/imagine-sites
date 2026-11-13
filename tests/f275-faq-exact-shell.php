<?php

declare(strict_types=1);

/** F1-275: structural, non-canonical FAQ shell mutations remain denied. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Production|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('F1-275 unsafe class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\InertPreviewAudit as Audit;
use Imagine\Sites\Production\SyntheticReleaseBundle as Bundle;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Domain\ReleaseManifest as Manifest;

function mutate275(string $html,string $before,string $after):string
{
    $start=strpos($html,
        '<section class="faq" id="perguntas" aria-labelledby="faq-title">');
    if($start===false)throw new RuntimeException('missing canonical FAQ');
    $end=strpos($html,'</section>',$start);
    if($end===false)throw new RuntimeException('missing FAQ end');
    $section=substr($html,$start,$end-$start);
    if(!str_contains($section,$before))
        throw new RuntimeException('missing mutation anchor');
    $mutated=str_replace($before,$after,$section);
    if($mutated===$section)throw new RuntimeException('mutation unchanged');
    return substr($html,0,$start).$mutated.substr($html,$end);
}
function rejects275(string $html):bool
{
    $artifact=new ReleaseArtifact($html);
    $manifest=new Manifest('tenant-f275','site-f275',
        'release-f275-new-hash',$artifact->digest);
    try{
        new Bundle($manifest,$artifact);
    }catch(DomainException){return true;}
    return false;
}
$copy=json_decode((string)file_get_contents(
    __DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'
),true,16,JSON_THROW_ON_ERROR);
$count=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $draft=Draft::build(
            $product,'Marca Fictícia Imagine',$style,$copy,
            'tenant-f275','site-f275','workflow-f275-'.$product.'-'.$style,
            'release-f275-'.$product.'-'.$style,officialFaqDisclosure:true
        );
        $html=$draft->artifact->html;
        if(!Audit::isSafe($html) ||
            !Bundle::verifyFiles($draft->bundle->files,$draft->manifest)) {
            throw new RuntimeException('valid canonical FAQ rejected: '.$product.'-'.$style);
        }
        ++$count;
        $mutations=[
            ['        <ul>',"<p>Extra information not in approved renderer</p>\n        <ul>"],
            ['      </div>',"<span>Extra synthetic element</span>\n      </div>"],
            ['<h2 id="faq-title">','<h2 class="extra" id="faq-title">'],
            ['Perguntas frequentes</h2>','Perguntas ampliadas</h2>'],
            ['        <ul>','        <ol>'],
            ['        </ul>','        </ol>'],
            ["        <ul>\n","        <ul>\n\n"],
            ['<li><details><summary>','<li> <details><summary>'],
            ['</details></li>','</details> </li>'],
            ["        </ul>\n", "        </ul>\n\n"],
            ["      <div class=\"container\">\n",
                "      <div class=\"container\">\n      <div class=\"extra\">\n"],
        ];
        foreach($mutations as [$before,$after]) {
            $changed=mutate275($html,$before,$after);
            if($changed===$html || Audit::isSafe($changed) || !rejects275($changed)) {
                throw new RuntimeException('F1-275 rehashed shell mutation passed: '.$product.'-'.$style);
            }
            ++$count;
        }
    }
}
if($count!==72)throw new RuntimeException('F1-275 fixture count drift '.$count);
echo "$count F1-275 exact FAQ shell tests passed (including rehashed bundles).\n";
