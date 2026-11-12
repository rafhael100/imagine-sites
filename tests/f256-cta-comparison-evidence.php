<?php

declare(strict_types=1);

/** F1-256: immutable CTA-only evidence, no Portal signature or publication. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unknown F1-256 class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticSecondaryCtaChangeEvidenceV1 as Evidence;

$count=0;
function check256(bool $condition,string $message):void {
    global $count;
    if (!$condition) throw new RuntimeException('F1-256 '.$message);
    ++$count;
}
function deny256(callable $op):bool {
    try {$op();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
foreach (['onepage','landing_page'] as $product) {
    foreach (['classic','modern','warm'] as $style) {
        $id=$product.'-'.$style;
        $args=[$product,'Aurora Exemplo',$style,$copy,'tenant-f256','site-f256'];
        $off=Draft::build(
            ...[...$args,'workflow-f256-'.$id.'-off','release-f256-'.$id.'-off'],
            officialSecondaryCallToAction:false
        );
        $on=Draft::build(
            ...[...$args,'workflow-f256-'.$id.'-on','release-f256-'.$id.'-on'],
            officialSecondaryCallToAction:true
        );
        $e=Evidence::between($off,$on);
        check256($e->stillMatches($off,$on) &&
            hash_equals(hash('sha256',json_encode(
                $e->record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
            )),$e->recordDigest),'canonical comparison digest: '.$id);
        check256($e->record['beforeHtmlSha256']===$off->artifact->digest &&
            $e->record['afterHtmlSha256']===$on->artifact->digest &&
            $e->record['beforeEnabled']===false &&
            $e->record['afterEnabled']===true &&
            $e->record['copyUnchanged']===true &&
            $e->record['onlyLocalContactAnchor']===true,
            'exact CTA-only before/after identity: '.$id);
        check256(!$e->canPublish() &&
            !$e->canApproveForClient() && !$e->canWriteCrm() &&
            !$e->canReuseApproval() &&
            $e->record['reviewState']==='pending' &&
            $e->record['authority']==='none' &&
            $e->record['publishable']===false &&
            $e->record['officialClientAcceptance']===false,
            'comparison cannot grant operational rights: '.$id);
        check256(deny256(static fn()=>Evidence::between($on,$off)) &&
            deny256(static fn()=>Evidence::between($on,$on)),
            'swapped or identical sources denied: '.$id);

        $otherTenant=Draft::build(
            ...[$product,'Aurora Exemplo',$style,$copy,
                'another-tenant','site-f256',
                'workflow-other-'.$id,'release-other-'.$id],
            officialSecondaryCallToAction:true
        );
        check256(deny256(static fn()=>Evidence::between($off,$otherTenant)) &&
            !$e->stillMatches($off,$otherTenant),
            'cross-tenant source rejected: '.$id);
        $changed=$copy;
        $changed['headline']='Uma manchete diferente e fictícia';
        $modified=Draft::build(
            ...[$product,'Aurora Exemplo',$style,$changed,
                'tenant-f256','site-f256',
                'workflow-drift-'.$id,'release-drift-'.$id],
            officialSecondaryCallToAction:true
        );
        check256(deny256(static fn()=>Evidence::between($off,$modified)) &&
            !$e->stillMatches($off,$modified),
            'other copy changes cannot hide in CTA comparison: '.$id);
    }
}
echo "$count F1-256 CTA comparison evidence assertions passed; no client acceptance.\n";
