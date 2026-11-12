<?php

declare(strict_types=1);

/**
 * F1-231: HTML equality is not identity equality.
 * UI preview (f222) and CLI private export (f223) deliberately use
 * independent synthetic tenant/site/release references. No client identity,
 * acceptance, production transport or indexing can follow from an HTML hash.
 */
spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unsafe cross-scope SEO lab autoload.');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticSeoCopyFixtureV1 as Fixture;
use Imagine\Sites\Production\SyntheticPrivateSeoChangeEvidenceV1 as Evidence;
use Imagine\Sites\Production\SyntheticPrivateSeoCopyDiffV1 as Diff;
use Imagine\Sites\Production\SyntheticPrivateSeoSnippetReadbackV1 as Readback;

$checks=0;
function assert231(bool $condition,string $reason):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-231 '.$reason);
    ++$checks;
}
function rejected231(callable $operation):bool {
    try{$operation();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}

$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);

$evidences=[];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach(['off','on'] as $social) {
            $uiBefore=Fixture::build($copy,$product,$preset,'default',$social,'f222');
            $uiAfter=Fixture::build($copy,$product,$preset,'custom',$social,'f222');
            $cliBefore=Fixture::build($copy,$product,$preset,'default',$social,'f223');
            $cliAfter=Fixture::build($copy,$product,$preset,'custom',$social,'f223');

            $uiEvidence=Evidence::fromVerifiedPair(
                $uiBefore->files,$uiBefore->draft->brief,
                $uiAfter->files,$uiAfter->draft->brief
            );
            $cliEvidence=Evidence::fromVerifiedPair(
                $cliBefore->files,$cliBefore->draft->brief,
                $cliAfter->files,$cliAfter->draft->brief
            );

            assert231(
                $uiBefore->draft->artifact->html===$cliBefore->draft->artifact->html &&
                $uiAfter->draft->artifact->html===$cliAfter->draft->artifact->html &&
                $uiEvidence->record['beforeHtmlSha256']===$cliEvidence->record['beforeHtmlSha256'] &&
                $uiEvidence->record['afterHtmlSha256']===$cliEvidence->record['afterHtmlSha256'],
                'both channels render identical HTML without binding identical identity'
            );
            $uiProof=Readback::inspect($uiBefore->files,$uiBefore->draft->brief);
            $cliProof=Readback::inspect($cliBefore->files,$cliBefore->draft->brief);
            assert231(
                $uiProof->tenantRef!==$cliProof->tenantRef &&
                $uiProof->siteRef!==$cliProof->siteRef &&
                $uiProof->releaseRef!==$cliProof->releaseRef &&
                $uiEvidence->record['scopeCorrelationSha256']!==
                    $cliEvidence->record['scopeCorrelationSha256'] &&
                $uiEvidence->record['beforeReleaseRefSha256']!==
                    $cliEvidence->record['beforeReleaseRefSha256'] &&
                $uiEvidence->digest!==$cliEvidence->digest,
                'same HTML must not collapse distinct scoped evidence'
            );
            assert231(
                rejected231(static fn()=>Diff::inspect(
                    $uiBefore->files,$uiBefore->draft->brief,
                    $cliAfter->files,$cliAfter->draft->brief
                )) &&
                rejected231(static fn()=>Diff::inspect(
                    $cliBefore->files,$cliBefore->draft->brief,
                    $uiAfter->files,$uiAfter->draft->brief
                )) &&
                rejected231(static fn()=>Evidence::fromVerifiedPair(
                    $uiBefore->files,$uiBefore->draft->brief,
                    $cliAfter->files,$cliAfter->draft->brief
                )),
                'foreign namespace must be rejected even when content is byte-identical'
            );
            assert231(
                !$uiEvidence->stillMatchesVerifiedPair(
                    $cliBefore->files,$cliBefore->draft->brief,
                    $cliAfter->files,$cliAfter->draft->brief
                ) &&
                !$cliEvidence->stillMatchesVerifiedPair(
                    $uiBefore->files,$uiBefore->draft->brief,
                    $uiAfter->files,$uiAfter->draft->brief
                ),
                'replay of one channel evidence in another must fail verification'
            );
            assert231(
                !$uiEvidence->canIndex() && !$uiEvidence->canPublish() &&
                !$cliEvidence->canIndex() && !$cliEvidence->canPublish() &&
                !$uiEvidence->canApproveForClient() &&
                !$cliEvidence->canWriteSeoEngine(),
                'neither channel can turn content equivalence into authorization'
            );
            $evidences[]=$uiEvidence->digest;
            $evidences[]=$cliEvidence->digest;
        }
    }
}
assert231(count($evidences)===24 && count(array_unique($evidences))===24,
    'all twelve visual combinations remain separately scoped in both channels');

echo $checks." F1-231 cross-channel SEO provenance assertions passed; no operational authority.\n";
