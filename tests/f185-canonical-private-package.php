<?php

declare(strict_types=1);

/**
 * F1-185: canonical pending-only four-file package for editorial + layout.
 * Pure synthetic PHP, no privileged service and no filesystem writer.
 */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unsafe fixture class');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Draft;
use Imagine\Sites\Production\SyntheticPrivateReviewPackageV1 as Pack;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verify;

$checks=0;
function check185(bool $ok,string $why):void {
    global $checks;
    if(!$ok)throw new RuntimeException('F1-185 '.$why);
    ++$checks;
}
function denied185(callable $action):bool {
    try {$action();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$orders=[
    ['highlights','process','faq'],
    ['faq','process','highlights'],
];
$seen=[];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $preset) {
        foreach($orders as $i=>$order) {
            foreach($product==='onepage'?['on','off']:['off'] as $mode) {
                $release='release-f185-'.$product.'-'.$preset.'-'.$i.'-'.$mode;
                $draft=Draft::build(
                    $product,'Marca Fictícia Imagine',$preset,$copy,
                    'tenant-f185','site-f185','workflow-f185-'.$product.'-'.$preset.'-'.$i.'-'.$mode,
                    $release,$order,$mode==='on'
                );
                $files=Pack::fromPendingDraft($draft);
                $keys=[
                    'index.html',
                    'internal-review-dossier.json',
                    'internal-review-draft.json',
                    'release-manifest.json',
                ];
                check185(array_keys($files)===$keys,
                    'private package always uses four canonical names and order');
                $proof=Verify::verifyBytes($files);
                check185($proof['schemaVersion']==='sites.synthetic.private-draft-verification.v1' &&
                    $proof['workflowRevision']===2 &&
                    $proof['reviewerDecisionStatus']==='pending' &&
                    $proof['publishable']===false &&
                    $proof['officialClientAcceptance']===false &&
                    $proof['externalPortalAuthorityRequired']===true &&
                    $proof['releaseRef']===$release,
                    'canonical package independently verifies as review-only');
                check185(hash('sha256',$files['index.html'])===$draft->artifact->digest &&
                    $proof['artifactDigest']===$draft->artifact->digest &&
                    $proof['bundleDigest']===$draft->bundle->bundleDigest,
                    'source artifact and manifest stay content-addressed');
                check185($files===Pack::fromPendingDraft($draft),
                    'four-file serialization is deterministic for same pending draft');
                check185(!str_contains($files['index.html'],'<script') &&
                    !str_contains($files['index.html'],'<form') &&
                    str_contains($files['index.html'],'noindex, nofollow'),
                    'canonical package never contains active lead capture');
                $seen[]=$proof['artifactDigest'];

                $bad=$files;
                $bad['index.html'].='<!-- tampered -->';
                check185(denied185(static fn()=>Verify::verifyBytes($bad)),
                    'artifact appended after digest cannot pass canonical proof');
                $summary=json_decode($files['internal-review-draft.json'],
                    true,16,JSON_THROW_ON_ERROR);
                $summary['publishable']=true;
                $bad=$files;
                $bad['internal-review-draft.json']=json_encode($summary,
                    JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
                check185(denied185(static fn()=>Verify::verifyBytes($bad)),
                    'publishable=true cannot enter canonical pending package');
                $dossier=json_decode($files['internal-review-dossier.json'],
                    true,16,JSON_THROW_ON_ERROR);
                $dossier['reviewerDecisionStatus']='approved';
                $bad=$files;
                $bad['internal-review-dossier.json']=json_encode($dossier,
                    JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
                    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
                check185(denied185(static fn()=>Verify::verifyBytes($bad)),
                    'forged reviewer approval is rejected even with canonical JSON');
            }
        }
    }
}
check185(count($seen)===18 && count(array_unique($seen))===18,
    'distinct content versions never collide across tested variants');
echo "$checks F1-185 canonical four-file package and negative integrity checks passed.\n";
