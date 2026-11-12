<?php

declare(strict_types=1);

/** F1-190: optional fixed-grammar brand colors, never arbitrary CSS. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe color lab classname');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SyntheticBrandPaletteV1 as Palette;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticStyleRevisionPlan as Style;
use Imagine\Sites\Production\SyntheticEditorialPatchV1 as Patch;
use Imagine\Sites\Production\SyntheticCombinedLayoutRevisionPlan as Layout;
use Imagine\Sites\Production\SyntheticContentRevisionPlan as Content;
use Imagine\Sites\Production\InertPreviewAudit as Inert;
use Imagine\Sites\Production\SyntheticPreviewQaReport as QA;
use Imagine\Sites\Production\SyntheticReleaseBundle as Bundle;
use Imagine\Sites\Production\StructuredCopyCandidate as Copy;

$checks=0;
function assert190(bool $condition,string $why):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-190 '.$why);
    ++$checks;
}
function denied190(callable $action):bool {
    try {$action();} catch(InvalidArgumentException|DomainException) {return true;}
    return false;
}
$palettes=[
    'ocean'=>Palette::fromHex('#064e5b','#075a6b','#ffffff','#075a6b'),
    'plum'=>Palette::fromHex('#50204d','#673060','#ffe9fa','#50204d'),
    'forest'=>Palette::fromHex('#134a36','#1d5a43','#f4fff5','#134a36'),
];
$fixture=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$paletteCount=0;
foreach($palettes as $label=>$palette) {
    foreach($palette->contrast() as $section=>$ratio) {
        assert190($ratio>=4.5 && $ratio<=21.0,
            'white hero endpoints and CTA must meet WCAG 4.5:1 '.$label.' '.$section);
    }
    assert190(!str_contains($palette->cssRules(),'url(') &&
        !str_contains($palette->cssRules(),'<') &&
        !str_contains($palette->cssRules(),'@import') &&
        str_contains($palette->cssRules(),'.hero .btn { background: '),
        'brand colors cannot introduce arbitrary CSS resources or markup');
    $normalized=Palette::fromHex(
        strtoupper($palette->heroStart),
        strtoupper($palette->heroEnd),
        strtoupper($palette->buttonBackground),
        strtoupper($palette->buttonText)
    );
    assert190($normalized->cssRules()===$palette->cssRules() &&
        $normalized->contrast()===$palette->contrast(),
        'case-insensitive safe hex normalizes to identical canonical output');
    foreach(['onepage','landing_page'] as $product) {
        foreach(['classic','modern','warm'] as $preset) {
            $args=[
                $product,'Marca Fictícia Imagine',$preset,$fixture,
                'tenant-f190','site-f190',
                'workflow-f190-'.$product.'-'.$preset,
                'release-f190-'.$product.'-'.$preset
            ];
            $old=Draft::build(...$args);
            $new=Draft::build(...[...$args,
                ['highlights','process','faq'],false,$palette
            ]);
            $again=Draft::build(...[...$args,
                ['highlights','process','faq'],false,$palette
            ]);
            assert190($old->brief->brandPalette===null &&
                !str_contains($old->artifact->html,'.hero .btn { background: '),
                'old profiles render without added brand overrides');
            assert190($new->brief->brandPalette===$palette &&
                $new->artifact->digest!==$old->artifact->digest &&
                hash('sha256',$new->artifact->html)===$new->artifact->digest &&
                $new->artifact->digest===$again->artifact->digest &&
                $new->artifact->html===$again->artifact->html,
                'opt-in palettes generate separate deterministic content');
            assert190(str_contains($new->artifact->html,$palette->cssRules()) &&
                substr_count($new->artifact->html,'.hero .eyebrow { color: #ffffff; opacity: 1; }')===1 &&
                !str_contains($new->artifact->html,'<script') &&
                !str_contains($new->artifact->html,'<form'),
                'one exact static CSS allowlist block is appended');
            assert190(Inert::isSafe($new->artifact->html) &&
                QA::inspect($new->artifact)->passedStructuralChecks &&
                Bundle::verifyFiles($new->bundle->files,$new->manifest) &&
                $new->reviewSummary()['publishable']===false &&
                $new->reviewSummary()['officialClientAcceptance']===false,
                'brand palette preserves inert reviewed-only release guarantees');

            $approved=$new->workflow->advance(
                'imagine_reviewer','approve_internal',2,$new->artifact->digest
            );
            $style=Style::begin(
                $approved,$new->brief,$new->artifact,$new->manifest->releaseRef,
                $preset==='classic'?'modern':'classic',
                'release-f190-next-style-'.$label,'imagine_reviewer',3
            );
            assert190($style->nextBrief->brandPalette===$palette &&
                $style->draftWorkflow->artifactDigest===null,
                'changing theme preserves independent brand palette and revokes review');
            $layout=Layout::begin(
                $approved,$new->brief,$new->artifact,$new->manifest->releaseRef,
                ['faq','process','highlights'],false,
                'release-f190-next-order-'.$label,'imagine_reviewer',3
            );
            assert190($layout->nextBrief->brandPalette===$palette &&
                $layout->draftWorkflow->artifactDigest===null,
                'changing page order cannot drop brand palette');
            $patch=Patch::propose(
                $approved,$new->brief,$new->artifact,$new->manifest->releaseRef,
                'release-f190-next-headline-'.$label,
                'imagine_reviewer',[
                    'schemaVersion'=>'sites.editorial.patch.v1',
                    'expectedDigest'=>$new->artifact->digest,
                    'expectedRevision'=>3,
                    'changes'=>['headline'=>'Uma nova manchete para revisão privada'],
                ]
            );
            assert190($patch->nextBrief->brandPalette===$palette &&
                $patch->draftWorkflow->artifactDigest===null,
                'editorial patch preserves palette and requires a new review');

            $other=$palettes[$label==='ocean'?'plum':'ocean'];
            $forged=new SiteBrief(
                $product,$new->brief->brand,'Texto alterado pelo editor interno',
                $new->brief->description,$new->brief->callToAction,
                $new->brief->benefits,$preset,$new->brief->faq,
                $new->brief->highlights,$new->brief->processSteps,
                $new->brief->sectionOrder,$new->brief->sectionNavigation,$other
            );
            assert190(denied190(static fn()=>Content::begin(
                $approved,$new->brief,$new->artifact,
                $new->manifest->releaseRef,$forged,
                'release-f190-forged','imagine_reviewer',3
            )), 'plain editorial content cannot secretly replace visual branding');

            ++$paletteCount;
        }
    }
}
assert190($paletteCount===18,
    'three approved palettes across two products and three theme presets');

$invalid=[
    ['#123','#123456','#ffffff','#123456'],
    ['#1234567','#123456','#ffffff','#123456'],
    ['#zzzzzz','#123456','#ffffff','#123456'],
    ['#123456;url(x)','#123456','#ffffff','#123456'],
    ['red','#123456','#ffffff','#123456'],
    ['#fffffe','#123456','#ffffff','#123456'],
    ['#123456','#ffffff','#ffffff','#123456'],
    ['#123456','#345678','#ffffff','#f0f0f0'],
    ['#000000','#000000','#ffffff','#eeeeee'],
    ["#000000\ncolor:red",'#123456','#ffffff','#123456'],
];
foreach($invalid as $case) {
    assert190(denied190(static fn()=>Palette::fromHex(...$case)),
        'invalid hex, CSS injection or insufficient contrast denied');
}
$tainted=$fixture;
$tainted['brandPalette']=[$palettes['ocean']->heroStart];
assert190(denied190(static fn()=>Copy::assemble(
    'onepage','Marca Fictícia',$tainted
)), 'untrusted structured copy cannot choose official branding fields');
echo "$checks F1-190 high contrast synthetic brand palettes passed for 18 product/theme variants.\n";
