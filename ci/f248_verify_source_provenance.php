<?php

declare(strict_types=1);

/**
 * F1-248: reconstruct six REAL fictional preview drafts and verify their
 * exact editorial copy, generator source, SHA package and review revision.
 * No network, files modified, customer content or publishing authority.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' || getenv('SITES_F238_DEMO_ARTIFACT')!=='1') {
    throw new RuntimeException('F1-248 only in explicit GitHub CI test mode.');
}
require_once __DIR__.'/../apps/sites/vendor/autoload.php';

use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticDraftProvenanceV1 as Provenance;

$root=dirname(__DIR__);
$copyPath=$root.'/fixtures/sites/f1-170-process-copy.synthetic.json';
$rendererPath=$root.'/src/Production/StaticSiteRenderer.php';
$manifestPath=$root.'/apps/sites/storage/app/private/f238-demo-previews/manifest.json';

if (is_link($copyPath)||is_link($rendererPath)||is_link($manifestPath)) {
    throw new RuntimeException('F1-248 symlink not allowed.');
}
$copyBytes=(string)file_get_contents($copyPath);
$rendererBytes=(string)file_get_contents($rendererPath);
$copy=json_decode($copyBytes,true,16,JSON_THROW_ON_ERROR);
$manifest=json_decode((string)file_get_contents($manifestPath),true,16,JSON_THROW_ON_ERROR);
if ($manifest['sourceProvenanceSchema']!=='sites.synthetic.draft-provenance.v1' ||
    $manifest['sourceCopySha256']!==hash('sha256',$copyBytes) ||
    $manifest['rendererSourceSha256']!==hash('sha256',$rendererBytes) ||
    $manifest['publishable']!==false ||
    $manifest['approvedForClient']!==false) {
    throw new RuntimeException('F1-248 header digest/provenance mismatch.');
}

function assert248(bool $condition,string $reason):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-248 '.$reason);
    ++$checks;
}
$checks=0;
$seen=[];
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $draft=Assembly::build(
            $product,'Aurora Exemplo',$style,$copy,
            'tenant-f245-demo','site-f245-demo',
            'workflow-f245-'.$product.'-'.$style,
            'release-f245-'.$product.'-'.$style,
            ['highlights','process','faq'],$product==='onepage',
            null,true,$copy['headline'].' | Aurora Exemplo',$copy['description']
        );
        $record=Provenance::fromDraft($draft,$copyBytes,$rendererBytes);
        $file=$manifest['files'][$product.'-'.$style.'.html']??null;
        assert248(is_array($file) &&
            $file['sourceProvenanceSha256']===$record->recordDigest &&
            $file['privateBundleSha256']===$record->record['privateBundleSha256'] &&
            $file['workflowRevision']===$record->record['workflowRevision'] &&
            $file['sha256']===$record->record['artifactSha256'],
            'source+review+bundle exactly bind the generated gallery page');
        assert248($record->matchesDraft($draft,$copyBytes,$rendererBytes) &&
            $record->record['workflowRevision']===2 &&
            $record->record['workflowState']==='internal_review' &&
            $record->record['syntheticOnly']===true &&
            $record->record['internalReviewRequired']===true &&
            $record->record['portalAuthorityRequired']===true &&
            $record->record['officialClientAcceptance']===false &&
            $record->record['publishable']===false,
            'reviewed draft cannot be promoted by provenance');
        assert248(!$record->canPublish() && !$record->canApproveForClient() &&
            !$record->canWriteCrm() && !$record->canIndex(),
            'provenance can never grant operational authority');
        assert248(!$record->matchesDraft($draft,$copyBytes,$rendererBytes."\n"),
            'change in renderer source bytes denied even if HTML stays identical');
        $altered=str_replace('Experiências digitais',
            'Experiências virtuais', $copyBytes);
        assert248($altered!==$copyBytes &&
            !$record->matchesDraft($draft,$altered,$rendererBytes),
            'change in copy source bytes denied even if someone keeps release digest');
        $seen[$record->recordDigest]=true;
    }
}
assert248(count($seen)===6,'six unique workflow/provenance digests');
$bad=$copy;
$bad['publishable']=true;
try {
    Provenance::fromDraft(
        Assembly::build(
            'onepage','Aurora Exemplo','classic',$copy,
            'tenant-f245-demo','site-f245-demo',
            'workflow-f245-onepage-classic','release-f245-onepage-classic',
            ['highlights','process','faq'],true,
            null,true,$copy['headline'].' | Aurora Exemplo',$copy['description']
        ),
        json_encode($bad,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
        $rendererBytes
    );
    throw new RuntimeException('F1-248 accepted extra publishing authority in copy');
} catch (DomainException|\InvalidArgumentException) {
    assert248(true,'untrusted copy cannot append publication power');
}
echo "$checks F1-248 source bytes, template, six draft bundles and review provenance verified.\n";
