<?php

declare(strict_types=1);

/** F1-257: verify exact 4-file private CTA snapshots, no operational rights. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unexpected private CTA parity fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as Package;
use Imagine\Sites\Production\SyntheticPrivateSecondaryCtaParityV1 as Parity;

$checks=0;
function ok257(bool $condition,string $label):void {
    global $checks;
    if (!$condition)throw new RuntimeException('F1-257 '.$label);
    ++$checks;
}
function reject257(callable $fn):bool {
    try {$fn();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $name=$product.'-'.$style;
        $args=[$product,'Marca Fictícia Imagine',$style,$copy,'tenant-f257','site-f257'];
        $off=Draft::build(
            ...[...$args,'workflow-f257-'.$name.'-off','release-f257-'.$name.'-off'],
            officialSecondaryCallToAction:false
        );
        $on=Draft::build(
            ...[...$args,'workflow-f257-'.$name.'-on','release-f257-'.$name.'-on'],
            officialSecondaryCallToAction:true
        );
        $offFiles=Package::fromPendingDraft($off);
        $onFiles=Package::fromPendingDraft($on);
        $proof=Parity::inspect($offFiles,$off->brief,$onFiles,$on->brief);
        ok257($proof->stillMatches(
            $offFiles,$off->brief,$onFiles,$on->brief
        ) && hash_equals(hash('sha256',$proof->json),$proof->digest),
            'canonical package proof reproducible: '.$name);
        ok257($proof->record['beforeHtmlSha256']===$off->artifact->digest &&
            $proof->record['afterHtmlSha256']===$on->artifact->digest &&
            count($proof->record['filesSha256']['before'])===4 &&
            count($proof->record['filesSha256']['after'])===4 &&
            !isset($proof->record['tenantRef']) &&
            !isset($proof->record['siteRef']) &&
            $proof->record['reviewState']==='pending',
            'file-level evidence without raw private scope: '.$name);
        ok257(!$proof->canPublish() && !$proof->canAcceptForClient() &&
            !$proof->canWriteCrm() && !$proof->canIndex(),
            'no authority from private archive: '.$name);
        ok257(reject257(static fn()=>Parity::inspect(
            $onFiles,$on->brief,$offFiles,$off->brief
        )), 'inverted CTA pair denied: '.$name);
        $bad=$onFiles;
        $bad['index.html']=str_replace(
            'noindex, nofollow','index, follow',$bad['index.html']
        );
        ok257(reject257(static fn()=>Parity::inspect(
            $offFiles,$off->brief,$bad,$on->brief
        )), 'changed noindex HTML denied: '.$name);
        $bad=$onFiles;
        $bad['release-manifest.json'].="\n ";
        ok257(reject257(static fn()=>Parity::inspect(
            $offFiles,$off->brief,$bad,$on->brief
        )), 'modified private manifest denied: '.$name);
        $bad=$onFiles;
        $bad['extra.json']='{}';
        ok257(reject257(static fn()=>Parity::inspect(
            $offFiles,$off->brief,$bad,$on->brief
        )), 'unexpected extra file denied: '.$name);

        $otherTenant=Draft::build(
            ...[$product,'Marca Fictícia Imagine',$style,$copy,
                'tenant-another-f257','site-f257',
                'workflow-other-'.$name,'release-other-'.$name],
            officialSecondaryCallToAction:true
        );
        $otherFiles=Package::fromPendingDraft($otherTenant);
        ok257(reject257(static fn()=>Parity::inspect(
            $offFiles,$off->brief,$otherFiles,$otherTenant->brief
        )), 'private cross-tenant archive denied: '.$name);
        $otherCopy=$copy;
        $otherCopy['description']='Outra descrição fictícia com revisão independente.';
        $copyDraft=Draft::build(
            ...[$product,'Marca Fictícia Imagine',$style,$otherCopy,
                'tenant-f257','site-f257',
                'workflow-drift-'.$name,'release-drift-'.$name],
            officialSecondaryCallToAction:true
        );
        ok257(reject257(static fn()=>Parity::inspect(
            $offFiles,$off->brief,Package::fromPendingDraft($copyDraft),$copyDraft->brief
        )),'additional copy drift denied: '.$name);
    }
}
echo "$checks F1-257 private-package CTA parity assertions passed; no deploy.\n";
