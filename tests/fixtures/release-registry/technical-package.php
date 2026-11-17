<?php
declare(strict_types=1);

use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Production\{ReleaseArtifact,SiteBrief,StaticSiteRenderer};
use Imagine\Sites\Publication\{
    SiteCommercialReleaseBinding,SiteCurrentCommercialReleaseBindingSource,SiteReleasePreparation,
};

// Included only after the parent contract verified its disposable PG18 target
// and completed the editorial cycle. Positive authority always comes from PDO.
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_RELEASE_REGISTRY_CI')!=='1' ||
    !isset($admin,$dbs,$tenants,$writers,$sources,$commands,$seedProject,$editorialWriters,$snapshot,$ok)) {
    throw new RuntimeException('Technical package requires the verified registry fixture');
}
$packageChecksBefore=$checks;
$refusePackage=static function(callable $operation,string $label)use($ok):void {
    try {$operation();} catch (DomainException $error) {
        $ok($error->getMessage()==='Release preparation unavailable.',$label.' exact preparation refusal');
        return;
    }
    $ok(false,'accepted: '.$label);
};

$packageSite='44444444-4444-4444-8444-444444444444';
$packageCommand=new SiteProjectCommand(
    '1','op-registry-package',$commands['a']->tenantRef,'order-registry-package',
    $commands['a']->contractRef,$commands['a']->contractRevision,'onepage','idem-registry-package',
);
$seedProject($tenants['a'],$packageSite,$packageCommand);
$packageArtifact=(new StaticSiteRenderer())->render(new SiteBrief(
    'onepage','Empresa fictícia do pacote','Pacote técnico para revisão interna',
    'Conteúdo sintético sem informações de pessoas ou serviços externos.',
    'Conheça o exemplo',['Conteúdo revisado','Identidade da versão preservada'],
));
$packageManifest=new ReleaseManifest(
    $packageCommand->tenantRef,$packageSite,'release-technical-package',$packageArtifact->digest,
);
$packageStored=$writers['a']['artifact_writer']->recordArtifact(
    $packageSite,$packageManifest->releaseRef,$packageArtifact->html,
);
$ok($packageStored['created']===true && $packageStored['digest']===$packageArtifact->digest,
    'technical package fixture records actual renderer bytes under the artifact writer');
$ok($editorialWriters['a']['editor']->transition($packageSite,0,'register',$packageArtifact->digest)['revision']===1,
    'technical package starts with a real editorial draft');
$ok($editorialWriters['a']['editor']->transition($packageSite,1,'submit',$packageArtifact->digest)['revision']===2,
    'technical package receives an actual review submission');
$ok($editorialWriters['a']['reviewer']->transition($packageSite,2,'approve_internal',$packageArtifact->digest)['revision']===3,
    'independent SQL reviewer approves the exact renderer digest internally');
$packageBinding=$writers['a']['binding_writer']->bind($packageSite,$packageManifest->releaseRef,3,0);
$ok($packageBinding->bindingRevision===1 &&
    $sources['a']->readCurrent($packageCommand,$packageManifest,3)==$packageBinding,
    'technical package has a current persistent association from the real PDO reader');

$packagePersisted=$admin->prepare(
    'SELECT html FROM sites_core.release_artifacts WHERE tenant_id=?::uuid AND site_id=?::uuid AND release_ref=?'
);
$packagePersisted->execute([$tenants['a'],$packageSite,$packageManifest->releaseRef]);
$packagePersistedHtml=$packagePersisted->fetchColumn();
$packageBefore=$snapshot();
$packageReadOnlyBefore=$dbs['a']['reader']->query('SHOW default_transaction_read_only')->fetchColumn();
$ok(in_array($packageReadOnlyBefore,['on','off'],true),'known PDO reader transaction mode before packaging');
$dbs['a']['reader']->exec('SET default_transaction_read_only=on');
try {
    $ok($dbs['a']['reader']->query('SHOW transaction_read_only')->fetchColumn()==='on',
        'package preparation uses a read-only SQL session without a caller-owned transaction');
    $packagePreparation=new SiteReleasePreparation($sources['a']);
    $packageResult=$packagePreparation->prepare($packageCommand,$packageManifest,3,$packageArtifact);
    $packageKeys=array_keys($packageResult);sort($packageKeys);
    $ok($packageKeys===['artifactDigest','bindingRevision','editorialRevision','files','packageDigest'],
        'technical package exposes only its five declared result fields');
    $packageFiles=$packageResult['files'];
    $packageFileNames=array_keys($packageFiles);sort($packageFileNames);
    $ok($packageFileNames===['index.html','readiness.json','release-manifest.json'],
        'technical package contains exactly HTML, manifest and readiness files');
    $ok($packageFiles['index.html']===$packageArtifact->html &&
        $packageFiles['index.html']===$packagePersistedHtml &&
        $packageResult['artifactDigest']===hash('sha256',$packageFiles['index.html']),
        'packaged HTML preserves every byte of the renderer and immutable registry artifact');
    $ok($packageResult['bindingRevision']===1 && $packageResult['editorialRevision']===3,
        'package identifies the exact persisted binding and reviewed revision');
    $packageMetadata=json_decode($packageFiles['release-manifest.json'],true,16,JSON_THROW_ON_ERROR);
    $packageExpectedMetadata=[
        'schemaVersion'=>'sites.technical-release-package.v1','purpose'=>'internal_technical_review',
        'tenantRef'=>$packageCommand->tenantRef,'orderRef'=>$packageCommand->orderRef,
        'contractRef'=>$packageCommand->contractRef,'contractRevision'=>$packageCommand->contractRevision,
        'productCode'=>'onepage','siteRef'=>$packageSite,'releaseRef'=>$packageManifest->releaseRef,
        'artifactDigest'=>$packageArtifact->digest,'editorialRevision'=>3,'bindingRevision'=>1,
        'commandDigest'=>$packageCommand->semanticFingerprint(),'artifactSizeBytes'=>strlen($packageArtifact->html),
        'publishable'=>false,'officialClientAcceptance'=>false,'commercialAuthorityVerified'=>false,
        'requiresCurrentRevalidation'=>true,
    ];
    ksort($packageMetadata);ksort($packageExpectedMetadata);
    $ok($packageMetadata===$packageExpectedMetadata,
        'manifest preserves exact commercial and release identity while denying publication and official acceptance');
    $packageReadiness=json_decode($packageFiles['readiness.json'],true,16,JSON_THROW_ON_ERROR);
    $ok(($packageReadiness['schemaVersion']??null)==='sites.technical-release-readiness.v1' &&
        ($packageReadiness['status']??null)==='prepared_for_internal_review' &&
        ($packageReadiness['canPublish']??null)===false &&
        ($packageReadiness['canAcceptRealOrders']??null)===false &&
        ($packageReadiness['independentEvidence']??null)==='not_evaluated' &&
        ($packageReadiness['requiresCurrentRevalidation']??null)===true,
        'readiness never claims payment, customer acceptance, publication or admission of real orders');
    $ok($packageResult['packageDigest']===hash('sha256',
        $packageFiles['index.html']."\0".$packageFiles['release-manifest.json']."\0".$packageFiles['readiness.json']),
        'package digest covers all three delivered files in the declared order');
    $ok($packagePreparation->prepare($packageCommand,$packageManifest,3,$packageArtifact)===$packageResult,
        'repeated current preparation returns identical bytes and metadata');

    $refusePackage(fn()=>(new SiteReleasePreparation())->prepare($packageCommand,$packageManifest,3,$packageArtifact),
        'missing independent registry source denies preparation');
    $packageWrongManifest=new ReleaseManifest(
        $packageCommand->tenantRef,$packageSite,$packageManifest->releaseRef,hash('sha256','unrelated technical package'),
    );
    $refusePackage(fn()=>$packagePreparation->prepare($packageCommand,$packageWrongManifest,3,$packageArtifact),
        'manifest digest cannot substitute other bytes');
    $packageChangedArtifact=new ReleaseArtifact(str_replace(
        'Pacote técnico para revisão interna','Conteúdo alterado sem revisão',$packageArtifact->html,
    ));
    $ok($packageChangedArtifact->digest!==$packageArtifact->digest,'HTML tamper fixture changes actual renderer bytes');
    $refusePackage(fn()=>$packagePreparation->prepare($packageCommand,$packageManifest,3,$packageChangedArtifact),
        'changed HTML cannot use the original manifest');
    $packageChangedManifest=new ReleaseManifest(
        $packageCommand->tenantRef,$packageSite,$packageManifest->releaseRef,$packageChangedArtifact->digest,
    );
    $refusePackage(fn()=>$packagePreparation->prepare($packageCommand,$packageChangedManifest,3,$packageChangedArtifact),
        'self-consistent changed HTML and manifest cannot borrow the persisted review or binding');
    $packageOtherOrder=new SiteProjectCommand(
        '1','op-registry-package-other',$packageCommand->tenantRef,'order-registry-package-other',
        $packageCommand->contractRef,$packageCommand->contractRevision,'onepage','idem-registry-package-other',
    );
    $refusePackage(fn()=>$packagePreparation->prepare($packageOtherOrder,$packageManifest,3,$packageArtifact),
        'same-tenant different order cannot borrow an exact release');
    $ok($snapshot()===$packageBefore,
        'successful and rejected preparations append no artifact, editorial revision or binding');

    // The hook returns the first genuine SQL result, then commits a genuine SQL
    // revocation. No fabricated positive binding substitutes for the registry.
    $packageRevokedRevision=null;
    $packageRevoke=static function()use($writers,$packageSite,&$packageRevokedRevision):void {
        $packageRevokedRevision=$writers['a']['revoker']->revoke($packageSite,1);
    };
    $packageInterruptedSource=new class($sources['a'],$packageRevoke) implements SiteCurrentCommercialReleaseBindingSource {
        public int $reads=0;
        public function __construct(
            private SiteCurrentCommercialReleaseBindingSource $source,
            private Closure $afterFirstRead,
        ) {}
        public function readCurrent(
            SiteProjectCommand $command,ReleaseManifest $manifest,int $editorialRevision,
        ):?SiteCommercialReleaseBinding {
            $binding=$this->source->readCurrent($command,$manifest,$editorialRevision);
            ++$this->reads;
            if ($this->reads===1 && $binding!==null) ($this->afterFirstRead)();
            return $binding;
        }
    };
    $refusePackage(fn()=>(new SiteReleasePreparation($packageInterruptedSource))->prepare(
        $packageCommand,$packageManifest,3,$packageArtifact,
    ),'real binding revocation during preparation denies the package');
    $ok($packageInterruptedSource->reads===2 && $packageRevokedRevision===2,
        'preparation rereads PDO authority after the actual revoker commits');
    $packageAfterRevocation=$snapshot();
    $ok($packageAfterRevocation['artifacts']===$packageBefore['artifacts'] &&
        $packageAfterRevocation['revisions']===$packageBefore['revisions'] &&
        (int)$packageAfterRevocation['bindings']===(int)$packageBefore['bindings']+1,
        'only the explicit revocation hook writes one immutable binding event');
    $refusePackage(fn()=>$packagePreparation->prepare($packageCommand,$packageManifest,3,$packageArtifact),
        'the original PDO source also denies subsequent preparation after revocation');
    $ok($snapshot()===$packageAfterRevocation,'rejected preparation of a revoked package writes nothing');
} finally {
    $dbs['a']['reader']->exec($packageReadOnlyBefore==='on'
        ? 'SET default_transaction_read_only=on' : 'SET default_transaction_read_only=off');
}
echo 'TECHNICAL PACKAGE CI PASS: '.($checks-$packageChecksBefore).
    " assertions; real renderer, editorial roles, registry PDO and revocation; no publication\n";
