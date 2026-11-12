<?php

declare(strict_types=1);

/** F1-228: read-only, reproducible evidence; no approval or external IO. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $part = substr($class, strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D', $part)) {
        throw new RuntimeException('Unsafe class requested from F1-228 test.');
    }
    require __DIR__.'/../src/'.str_replace('\\', '/', $part).'.php';
});

use Imagine\Sites\Production\SyntheticSeoCopyFixtureV1 as Fixture;
use Imagine\Sites\Production\SyntheticPrivateSeoChangeEvidenceV1 as Evidence;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as Package;

$checks = 0;
function check228(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('F1-228: '.$label);
    ++$checks;
}
function denied228(callable $action): bool {
    try { $action(); } catch (DomainException|InvalidArgumentException) { return true; }
    return false;
}
$copy = json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true, 16, JSON_THROW_ON_ERROR
);
$distinct=[];
foreach (['onepage','landing_page'] as $product) {
    foreach (['classic','modern','warm'] as $preset) {
        foreach (['off','on'] as $social) {
            $before=Fixture::build($copy,$product,$preset,'default',$social);
            $after=Fixture::build($copy,$product,$preset,'custom',$social);
            $priorFiles=$before->files;
            $nextFiles=$after->files;
            $evidence=Evidence::fromVerifiedPair(
                $priorFiles,$before->draft->brief,$nextFiles,$after->draft->brief
            );
            $again=Evidence::fromVerifiedPair(
                $priorFiles,$before->draft->brief,$nextFiles,$after->draft->brief
            );
            check228(
                $evidence->json===$again->json &&
                $evidence->digest===$again->digest &&
                hash('sha256',$evidence->json)===$evidence->digest &&
                $evidence->stillMatchesVerifiedPair(
                    $priorFiles,$before->draft->brief,$nextFiles,$after->draft->brief
                ), 'canonical JSON and digest stable across repeated audits'
            );
            $data=json_decode($evidence->json,true,8,JSON_THROW_ON_ERROR);
            check228(
                $data['reviewState']==='pending' &&
                $data['authority']==='none' &&
                $data['changedFields']===['seoTitle','seoDescription'] &&
                $data['beforeHtmlSha256']===$before->draft->artifact->digest &&
                $data['afterHtmlSha256']===$after->draft->artifact->digest &&
                $data['socialMetadataEnabled']===($social==='on') &&
                $data['clientAccepted']===false &&
                $data['publishable']===false &&
                $data['searchIndexingAllowed']===false &&
                $data['seoEngineWriteAllowed']===false,
                'evidence holds exact digests but never grants authority'
            );
            check228(
                count($data['filesSha256'])===2 &&
                count($data['filesSha256']['default'])===4 &&
                count($data['filesSha256']['custom'])===4 &&
                $data['filesSha256']['default']['index.html']===
                    hash('sha256',$priorFiles['index.html']) &&
                $data['filesSha256']['custom']['index.html']===
                    hash('sha256',$nextFiles['index.html']),
                'all four package files on both sides are pinned by SHA-256'
            );
            check228(
                !str_contains($evidence->json, Fixture::CUSTOM_TITLE) &&
                !str_contains($evidence->json, Fixture::CUSTOM_DESCRIPTION) &&
                !str_contains($evidence->json, 'tenant-f223') &&
                !str_contains($evidence->json, 'site-f223') &&
                !str_contains($evidence->json, 'https://') &&
                !$evidence->canPublish() && !$evidence->canIndex() &&
                !$evidence->canApproveForClient() &&
                !$evidence->canWriteSeoEngine(),
                'no private text, raw scope references, remote transport or publish'
            );
            check228(
                $priorFiles===$before->files && $nextFiles===$after->files,
                'evidence never rewrites either source'
            );
            $tampered=$nextFiles;
            $tampered['index.html']=str_replace(
                'noindex, nofollow','index, follow',$tampered['index.html'],$mutations
            );
            check228($mutations===1 &&
                denied228(static fn()=>Evidence::fromVerifiedPair(
                    $priorFiles,$before->draft->brief,$tampered,$after->draft->brief
                )) &&
                !$evidence->stillMatchesVerifiedPair(
                    $priorFiles,$before->draft->brief,$tampered,$after->draft->brief
                ), 'tampered robots and stale audit source rejected'
            );
            check228(denied228(static fn()=>Evidence::fromVerifiedPair(
                $nextFiles,$after->draft->brief,$priorFiles,$before->draft->brief
            )),'reversed role does not count as a valid editorial revision');
            $distinct[]=$evidence->digest;
        }
    }
}
check228(count($distinct)===12 && count(array_unique($distinct))===12,
    'twelve pairs produce distinct evidence digests');

// Same rendered bytes do not entitle one fictional tenant to review another.
$default=Fixture::build($copy,'onepage','classic','default','off');
$custom=Fixture::build($copy,'onepage','classic','custom','off');
$foreign=Draft::build(
    'onepage','Marca Fictícia Imagine','classic',$copy,
    'tenant-other','site-f223','workflow-foreign','release-foreign',
    ['highlights','process','faq'],false,null,false,
    Fixture::CUSTOM_TITLE, Fixture::CUSTOM_DESCRIPTION
);
$foreignFiles=Package::fromPendingDraft($foreign);
check228(
    $foreign->artifact->html===$custom->draft->artifact->html &&
    denied228(static fn()=>Evidence::fromVerifiedPair(
        $default->files,$default->draft->brief,$foreignFiles,$foreign->brief
    )),
    'identical HTML cannot mask cross-tenant package in evidence'
);
echo $checks." F1-228 private SEO review evidence assertions passed; no production authority.\n";
