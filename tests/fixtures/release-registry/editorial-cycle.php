<?php
declare(strict_types=1);

// Included by the already identity-verified disposable registry contract. This
// fixture exercises the real application writer and migration, never a UI claim.
if (getenv('GITHUB_ACTIONS')!=='true' || getenv('SITES_RELEASE_REGISTRY_CI')!=='1' ||
    !isset($admin,$dbs,$sites,$tenants,$writers,$sources,$commands,$freshManifest,$digest,$html,$ok,$reject,$sign,$keysApproval,$approvalSource,$witness,$dual)) {
    throw new RuntimeException('Editorial cycle requires the verified registry fixture');
}
$editorialWriters=[];
$refuseEditorial=static function(callable $operation,string $label)use($ok):void {
    try {$operation();} catch (DomainException $e) {
        $ok($e->getMessage()==='Sites editorial operation unavailable.',$label.' exact application refusal');
        return;
    }
    $ok(false,'accepted: '.$label);
};
foreach ($dbs as $tenantKey=>$roles) foreach (['editor','reviewer'] as $duty) {
    $editorialWriters[$tenantKey][$duty]=new App\Editorial\EditorialRevisionWriter(
        new Illuminate\Database\PostgresConnection($roles[$duty],'postgres','',['driver'=>'pgsql'])
    );
}
$editorialCount=(int)$admin->query('SELECT count(*) FROM sites_core.editorial_revisions')->fetchColumn();
$refuseEditorial(fn()=>$editorialWriters['a']['editor']->transition($sites['a'],3,'reopen',$digest),'editor cannot reopen internal approval');
$refuseEditorial(fn()=>$editorialWriters['a']['reviewer']->transition($sites['a'],2,'reopen',$digest),'reopening requires the current expected revision');
$refuseEditorial(fn()=>$editorialWriters['a']['reviewer']->transition($sites['a'],3,'reopen',hash('sha256','wrong-reviewed-bytes')),'reopening preserves exact reviewed digest');
$refuseEditorial(fn()=>$editorialWriters['a']['reviewer']->transition($sites['b'],3,'reopen',$digest),'reviewer cannot reopen another tenant');
$ok((int)$admin->query('SELECT count(*) FROM sites_core.editorial_revisions')->fetchColumn()===$editorialCount,'refused reopen requests append no history');
$reopened=$editorialWriters['a']['reviewer']->transition($sites['a'],3,'reopen',$digest);
$ok($reopened===['revision'=>4,'state'=>'draft','digest'=>$digest,'clientAccepted'=>false,'publishable'=>false],'reviewer reopens current internal approval as a new draft');
$ok($sources['a']->readCurrent($commands['a'],$freshManifest,3)===null,'reopened head immediately invalidates old persistent association');
$s=$admin->prepare('SELECT review_state,artifact_digest,client_accepted,publishable FROM sites_core.editorial_revisions WHERE tenant_id=?::uuid AND site_id=?::uuid AND revision=3');
$s->execute([$tenants['a'],$sites['a']]);
$ok($s->fetch(PDO::FETCH_ASSOC)===['review_state'=>'awaiting_portal_acceptance','artifact_digest'=>$digest,'client_accepted'=>false,'publishable'=>false],'prior internal approval is preserved as history');
$refuseEditorial(fn()=>$editorialWriters['a']['reviewer']->transition($sites['a'],4,'reopen',$digest),'draft cannot be reopened again');
$refuseEditorial(fn()=>$editorialWriters['a']['reviewer']->transition($sites['a'],4,'submit',$digest),'reviewer cannot submit editor content');
$refuseEditorial(fn()=>$editorialWriters['a']['editor']->transition($sites['a'],4,'revise',$digest),'revision requires actual new artifact bytes');
$revisedHtml=str_replace('Registro de versão fictícia.','Conteúdo fictício revisado após reabertura.',$html);
$revisedDigest=hash('sha256',$revisedHtml);
$ok($editorialWriters['a']['editor']->transition($sites['a'],4,'revise',$revisedDigest)['revision']===5,'editor creates changed draft from reopened head');
$ok($editorialWriters['a']['editor']->transition($sites['a'],5,'submit',$revisedDigest)['state']==='internal_review','changed draft enters a new independent review');
$writers['a']['artifact_writer']->recordArtifact($sites['a'],'release-a-reopened',$revisedHtml);
$reject(fn()=>$writers['a']['binding_writer']->bind($sites['a'],'release-a-reopened',6,4),'unreviewed new content cannot borrow previous internal approval','40001');
$reviewed=$editorialWriters['a']['reviewer']->transition($sites['a'],6,'approve_internal',$revisedDigest);
$ok($reviewed['revision']===7 && $reviewed['state']==='awaiting_portal_acceptance','new digest receives its own internal approval');
$newBinding=$writers['a']['binding_writer']->bind($sites['a'],'release-a-reopened',7,4);
$ok($newBinding->bindingRevision===5 && $newBinding->editorialRevision===7 && $newBinding->artifactDigest===$revisedDigest,'new reviewed bytes receive a new persistent association');
$revisedManifest=new Imagine\Sites\Domain\ReleaseManifest($commands['a']->tenantRef,$sites['a'],'release-a-reopened',$revisedDigest);
$ok($sources['a']->readCurrent($commands['a'],$revisedManifest,7)==$newBinding,'current PDO source exposes only new exact revision');
$ok(!$dual->verifyEvidence($commands['a'],$revisedManifest,7)->allowed,'earlier signed client acceptance cannot authorize a reopened release');
$approvalSource->proof=$sign([
    'schemaVersion'=>'sites.portal-client-acceptance.ed25519.v1','issuer'=>'imagine-portal','audience'=>'imagine-sites',
    'issuedAt'=>$now,'expiresAt'=>$now+60,'acceptedAt'=>$now-10,'tenantRef'=>$commands['a']->tenantRef,
    'siteRef'=>$sites['a'],'releaseRef'=>'release-a-reopened','artifactDigest'=>$revisedDigest,'editorialRevision'=>7,
    'acceptanceRef'=>'synthetic-registry-acceptance','acceptanceEpoch'=>4,
    'actorContext'=>'portal_client_authenticated_mfa','decision'=>'accepted_explicitly',
],$keysApproval);
$witness->expectedRevision=7;
$ok($dual->verifyEvidence($commands['a'],$revisedManifest,7)->allowed,'new exact signed synthetic acceptance plus independent witnesses and binding pass the original verifier');

// Replacing the capability must preserve OID/owner/ACL and all recorded rows.
$reopenMigration=require __DIR__.'/../../../apps/sites/database/migrations/2026_11_17_000006_allow_editorial_reopen.php';
$beforeCapabilityRollback=$snapshot();
$functionIdentity=$admin->query("SELECT oid,proowner,proacl::text FROM pg_proc WHERE oid='sites_core.advance_editorial_revision(uuid,integer,text,text)'::regprocedure")->fetch(PDO::FETCH_ASSOC);
$reopenMigration->down();
$ok($snapshot()===$beforeCapabilityRollback,'capability rollback preserves editorial and registry history');
$ok($admin->query("SELECT oid,proowner,proacl::text FROM pg_proc WHERE oid='sites_core.advance_editorial_revision(uuid,integer,text,text)'::regprocedure")->fetch(PDO::FETCH_ASSOC)===$functionIdentity,'capability rollback preserves function identity and runtime grants');
$refuseEditorial(fn()=>$editorialWriters['a']['reviewer']->transition($sites['a'],7,'reopen',$revisedDigest),'old function refuses newly added capability');
$reopenMigration->up();
$ok($snapshot()===$beforeCapabilityRollback && $sources['a']->readCurrent($commands['a'],$revisedManifest,7)==$newBinding,'reapplying capability preserves the exact current release');
