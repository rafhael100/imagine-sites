<?php

declare(strict_types=1);

/**
 * F1-95: real PostgreSQL18 Laravel sites_drafts append with independent
 * readback of four canonical private files, approved project admission and
 * exact current fictional gateway. No Portal, customer, web API or deploy.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_F94_PROJECT_ADMISSION_LAB') !== '1' ||
    getenv('SITES_F95_DRAFT_LEDGER_LAB') !== '1' ||
    getenv('DB_DATABASE') !== 'sites_f94_lab' ||
    getenv('DB_HOST') !== '127.0.0.1') {
    fwrite(STDERR,"F1-95 is restricted to an opted-in, disposable PG18 lab.\n");
    exit(1);
}
spl_autoload_register(static function(string $name): void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($name,$prefix))return;
    $relative=substr($name,strlen($prefix));
    if(!preg_match(
        '/\A(?:Production|Domain|Integration|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $relative
    ))throw new RuntimeException('Unsafe test class');
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Persistence\LabAuthorizedProjectAdmissionStore as Admission;
use Imagine\Sites\Persistence\LabVerifiedDraftRevisionStore as DraftStore;
use Imagine\Sites\Persistence\LabVerifiedLatestDraftReader as Latest;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verifier;
use Imagine\Sites\Production\SyntheticPrivateDraftWriter as Writer;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;

final class F95FictionalPortalGateway implements PortalProjectAuthorityGateway
{
    public bool $allowed=false;
    public int $calls=0;
    public ?Closure $onVerify=null;

    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        ++$this->calls;
        if ($this->onVerify !== null) {
            ($this->onVerify)();
        }
        return new PolicyDecision(
            $this->allowed,
            $this->allowed ? [] : ['f95_authority_not_granted']
        );
    }
}

$app = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f94_lab;connect_timeout=5',
    'sites_f94_app',
    (string)getenv('SITES_F94_APP_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$admin = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f94_lab;connect_timeout=5',
    'postgres',
    (string)getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);

$copy = [
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções digitais para pequenos negócios',
    'description'=>'Demonstração inteiramente fictícia com serviços, informações de atendimento, benefícios e detalhes para uma prévia de revisão.',
    'callToAction'=>'Conheça os serviços',
    'benefits'=>['Conteúdo claro','Orientação útil','Organização'],
];
$command = new SiteProjectCommand(
    '1','operation-f95-a','tenant-f95-a','order-f95-a',
    'contract-f95-a',1,'onepage','idempotency-f95-a'
);
$site=Admission::siteRefFor($command);
$first=Assembly::build(
    'onepage','Marca Fictícia','modern',$copy,
    $command->tenantRef,$site,'workflow-f95-a1','release-f95-a1'
);
$second=Assembly::build(
    'onepage','Marca Fictícia','warm',$copy,
    $command->tenantRef,$site,'workflow-f95-a2','release-f95-a2'
);
$foreign=Assembly::build(
    'onepage','Marca Fictícia','modern',$copy,
    'tenant-f95-other',$site,'workflow-f95-foreign','release-f95-foreign'
);
$bytes=static function($draft):array {
    $dossier=Dossier::fromDraft($draft);
    $summary=$draft->reviewSummary();
    $summary['reviewDossierDigest']=$dossier->digest;
    return [
        'index.html'=>$draft->artifact->html,
        'internal-review-dossier.json'=>$dossier->json,
        'internal-review-draft.json'=>json_encode(
            $summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
        )."\n",
        'release-manifest.json'=>$draft->bundle->files['release-manifest.json'],
    ];
};
$base=sys_get_temp_dir().'/sites-f95-'.bin2hex(random_bytes(9));
if(!mkdir($base,0700))throw new RuntimeException('Cannot create lab root');
$dir1=$base.'/sites-f1-72-onepage-modern-basic';
$dir2=$base.'/sites-f1-72-onepage-warm-basic';
$dirForeign=$base.'/sites-f1-72-onepage-classic-basic';
$gate=new F95FictionalPortalGateway();
$admission=new Admission($app,$gate);
$store=new DraftStore($app,$gate);
$checks=0;
function f95Assert(bool $condition,string $description):void {
    global $checks;
    if(!$condition)throw new RuntimeException('FAIL F1-95 '.$description);
    ++$checks;
    echo "PASS F1-95 $description\n";
}
function f95Denied(callable $fn):bool {
    try{$fn();}catch(DomainException){return true;}
    return false;
}
function f95DraftCount(PDO $db,string $tenant):int {
    $db->beginTransaction();
    try {
        $set=$db->prepare("SELECT set_config('imagine_sites.tenant_ref', ?, true)");
        $set->execute([$tenant]);
        return (int)$db->query('SELECT COUNT(*) FROM sites_drafts')->fetchColumn();
    } finally {
        if($db->inTransaction())$db->rollBack();
    }
}
function f95Clean(string $dir):void {
    if(!is_dir($dir)||is_link($dir))return;
    foreach([
        'index.html','internal-review-dossier.json',
        'internal-review-draft.json','release-manifest.json'
    ] as $name){
        $path=$dir.'/'.$name;
        if(is_file($path)||is_link($path))@unlink($path);
    }
    @rmdir($dir);
}
try {
    $proof1=Writer::create($dir1,$bytes($first));
    $proof2=Writer::create($dir2,$bytes($second));
    Writer::create($dirForeign,$bytes($foreign));
    f95Assert($proof1===Verifier::verifyDirectory($dir1) &&
        $proof2===Verifier::verifyDirectory($dir2),
        'two fixed private draft packages verified independently on disk');

    putenv('SITES_F95_DRAFT_LEDGER_LAB=0');
    f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir1,1)) &&
        $gate->calls===0,
        'disabled flag rejects before any gateway call or DB operation');
    putenv('SITES_F95_DRAFT_LEDGER_LAB=1');

    f95Assert(f95Denied(fn()=> (new DraftStore($app))->append(
        $command,$first,$dir1,1
    )), 'default Portal authority gateway denies any write');
    f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir1,1)) &&
        f95DraftCount($app,'tenant-f95-a')===0,
        'explicitly denied gateway cannot append draft');

    $gate->allowed=true;
    f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir1,1)) &&
        f95DraftCount($app,'tenant-f95-a')===0,
        'authorized command alone is insufficient without persisted project admission');
    $created=$admission->admit($command,$site);
    f95Assert($created['idempotentReplay']===false &&
        $created['publishable']===false,
        'authorized project and admission written before draft revision');

    f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir1,2)) &&
        f95DraftCount($app,'tenant-f95-a')===0,
        'first revision must start at 1 without gaps');

    $one=$store->append($command,$first,$dir1,1);
    f95Assert($one['tenantRef']===$command->tenantRef &&
        $one['siteRef']===$site &&
        $one['draftRevision']===1 &&
        $one['artifactDigest']===$first->artifact->digest &&
        $one['dossierDigest']===Dossier::fromDraft($first)->digest &&
        $one['workflowRef']==='workflow-f95-a1' &&
        $one['releaseRef']==='release-f95-a1' &&
        $one['verifiedPrivateLabFiles']===true &&
        $one['idempotentReplay']===false,
        'stored draft revision binds exact tenant/site/workflow/release/HTML/dossier');
    f95Assert($one['internalReviewRequired']===true &&
        $one['portalIdentityOperational']===false &&
        $one['officialClientAcceptance']===false &&
        $one['publishable']===false,
        'new persisted revision cannot become customer approval or publication');
    f95Assert(f95DraftCount($app,'tenant-f95-a')===1 &&
        f95DraftCount($app,'tenant-f95-other')===0,
        'RLS prevents other tenant seeing the draft');

    $replay=$store->append($command,$first,$dir1,1);
    f95Assert($replay['idempotentReplay']===true &&
        $replay['artifactDigest']===$one['artifactDigest'] &&
        f95DraftCount($app,'tenant-f95-a')===1,
        'exact latest revision replay is idempotent and does not rewrite data');

    // F1-97: deliberately change the package AFTER the first disk check,
    // while the fictional authority is being evaluated. The idempotent
    // branch must re-read disk before reporting success or committing.
    $replayHtml=$dir1.'/index.html';
    $replayOriginal=(string)file_get_contents($replayHtml);
    $gate->onVerify=static function() use ($replayHtml,$replayOriginal):void {
        file_put_contents($replayHtml,$replayOriginal."\n");
    };
    try {
        f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir1,1)) &&
            f95DraftCount($app,'tenant-f95-a')===1,
            'F1-97 idempotent replay denies package changed after first disk verification');
    } finally {
        $gate->onVerify=null;
        file_put_contents($replayHtml,$replayOriginal);
    }
    f95Assert($store->append($command,$first,$dir1,1)['idempotentReplay']===true,
        'F1-97 restored exact package permits idempotent replay');

    f95Assert(f95Denied(fn()=> $store->append($command,$second,$dir2,1)) &&
        f95DraftCount($app,'tenant-f95-a')===1,
        'same revision with changed artifact and release is rejected');
    f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir1,3)),
        'out-of-order revision 3 is rejected before revision 2');
    f95Assert(f95Denied(fn()=> $store->append($command,$foreign,$dirForeign,2)),
        'cross-tenant private draft never matches authorized command');
    f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir2,2)),
        'private disk dossier and in-memory draft must be identical');

    $htmlPath=$dir2.'/index.html';
    $original=(string)file_get_contents($htmlPath);
    file_put_contents($htmlPath,$original."\n");
    f95Assert(f95Denied(fn()=> $store->append($command,$second,$dir2,2)) &&
        f95DraftCount($app,'tenant-f95-a')===1,
        'tampered disk HTML is rejected without partial database row');
    file_put_contents($htmlPath,$original);

    $gate->allowed=false;
    f95Assert(f95Denied(fn()=> $store->append($command,$second,$dir2,2)) &&
        f95DraftCount($app,'tenant-f95-a')===1,
        'revoked gateway cannot append a revision while project remains');
    $gate->allowed=true;

    // Deterministic two-connection proof of the transaction advisory lock:
    // while writer A holds the tenant/site lock, writer B cannot acquire it.
    // This tests real PostgreSQL locking without racy sleeps or child forks.
    $otherApp = new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=sites_f94_lab;connect_timeout=5',
        'sites_f94_app',(string)getenv('SITES_F94_APP_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $lockKey=strlen($command->tenantRef).':'.$command->tenantRef.':'.$site;
    $otherLockKey=strlen('tenant-f95-other').':tenant-f95-other:'.$site;
    $app->beginTransaction();
    $otherApp->beginTransaction();
    try {
        $lock=$app->prepare(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key,0))'
        );
        $lock->execute(['key'=>$lockKey]);
        $lock->closeCursor();
        $try=$otherApp->prepare(
            'SELECT pg_try_advisory_xact_lock(hashtextextended(:key,0))'
        );
        $try->execute(['key'=>$lockKey]);
        $busy=$try->fetchColumn();
        f95Assert(in_array($busy,[false,'f','0'],true),
            'second PostgreSQL connection cannot acquire held tenant/site write lock');
        $try->execute(['key'=>$otherLockKey]);
        $independent=$try->fetchColumn();
        f95Assert(in_array($independent,[true,'t','1'],true),
            'different tenant/site retains a separately available advisory lock');
        $app->commit();
        $try->execute(['key'=>$lockKey]);
        $free=$try->fetchColumn();
        f95Assert(in_array($free,[true,'t','1'],true),
            'the lock becomes available after the first transaction commits');
    } finally {
        if($app->inTransaction())$app->rollBack();
        if($otherApp->inTransaction())$otherApp->rollBack();
    }

    // F1-98: readers share the same advisory lock key as writers.
    // Verify PostgreSQL's real two-connection lock compatibility rather
    // than claiming the local filesystem is transactional.
    $app->beginTransaction();
    $otherApp->beginTransaction();
    try {
        $readLock=$app->prepare(
            'SELECT pg_advisory_xact_lock_shared(hashtextextended(:key,0))'
        );
        $readLock->execute(['key'=>$lockKey]);
        $readLock->closeCursor();
        $tryExclusive=$otherApp->prepare(
            'SELECT pg_try_advisory_xact_lock(hashtextextended(:key,0))'
        );
        $tryExclusive->execute(['key'=>$lockKey]);
        f95Assert(in_array($tryExclusive->fetchColumn(),[false,'f','0'],true),
            'F1-98 shared reader lock blocks a concurrent same-site exclusive writer');
        $tryShared=$otherApp->prepare(
            'SELECT pg_try_advisory_xact_lock_shared(hashtextextended(:key,0))'
        );
        $tryShared->execute(['key'=>$lockKey]);
        f95Assert(in_array($tryShared->fetchColumn(),[true,'t','1'],true),
            'F1-98 independent same-site readers can hold shared locks simultaneously');
        $tryExclusive->execute(['key'=>$otherLockKey]);
        f95Assert(in_array($tryExclusive->fetchColumn(),[true,'t','1'],true),
            'F1-98 a reader does not block an independent tenant/site writer');
    } finally {
        if($app->inTransaction())$app->rollBack();
        if($otherApp->inTransaction())$otherApp->rollBack();
    }

    $two=$store->append($command,$second,$dir2,2);
    f95Assert($two['draftRevision']===2 &&
        $two['idempotentReplay']===false &&
        $two['artifactDigest']===$second->artifact->digest &&
        f95DraftCount($app,'tenant-f95-a')===2,
        'next sequential material draft revision is appended without mutation');
    f95Assert($store->append($command,$second,$dir2,2)['idempotentReplay']===true,
        'current revision 2 supports exact idempotent retry');
    f95Assert(f95Denied(fn()=> $store->append($command,$first,$dir1,1)),
        'old revision cannot be replayed as the current revision');
    f95Assert(f95Denied(fn()=> $store->append($command,$second,$dir2,3)),
        'releaseRef already used cannot masquerade as a third new revision');

    f95Assert(f95Denied(fn()=> (new DraftStore($admin,$gate))->append(
        $command,$second,$dir2,2
    )), 'administrator DB role is not an allowed application runtime');
    f95Assert((int)$app->query('SELECT COUNT(*) FROM sites_drafts')->fetchColumn()===0,
        'transaction-local tenant GUC does not leak into subsequent queries');

    $app->beginTransaction();
    try {
        f95Assert(f95Denied(fn()=> $store->append($command,$second,$dir2,2)),
            'preexisting caller transaction is refused, avoiding scope confusion');
    } finally {
        if($app->inTransaction())$app->rollBack();
    }
    f95Assert(f95DraftCount($app,'tenant-f95-a')===2 &&
        f95DraftCount($app,'tenant-f95-other')===0,
        'all negative tests left two valid private revisions in correct tenant');

    // F1-96: a read-only verification against the SAME persisted revision
    // ledger; only the current revision may be presented for internal review.
    $reader=new Latest($app,$gate);
    putenv('SITES_F96_LATEST_READBACK_LAB=0');
    f95Assert(f95Denied(fn()=> $reader->read($command,$dir2)),
        'F1-96 readback is disabled without separate opt-in');
    putenv('SITES_F96_LATEST_READBACK_LAB=1');
    f95Assert(f95Denied(fn()=> (new Latest($app))->read($command,$dir2)),
        'F1-96 default gateway denies private preview readback');
    $latest=$reader->read($command,$dir2);
    f95Assert($latest['schemaVersion']==='sites.synthetic.latest-verified-draft.v1' &&
        $latest['tenantRef']===$command->tenantRef &&
        $latest['siteRef']===$site &&
        $latest['draftRevision']===2 &&
        $latest['workflowRef']==='workflow-f95-a2' &&
        $latest['releaseRef']==='release-f95-a2' &&
        $latest['artifactDigest']===$second->artifact->digest &&
        $latest['dossierDigest']===Dossier::fromDraft($second)->digest &&
        $latest['storedRowAndPrivateFilesMatch']===true,
        'F1-96 latest persisted record matches independently reopened private files');
    f95Assert($latest['internalReviewRequired']===true &&
        $latest['externalPortalAuthorityRequired']===true &&
        $latest['officialClientAcceptance']===false &&
        $latest['publishable']===false &&
        !array_key_exists('html',$latest),
        'F1-96 readback never implies official client approval or exposes HTML');
    f95Assert(f95Denied(fn()=> $reader->read($command,$dir1)),
        'F1-96 older revision 1 cannot masquerade as the current revision 2');
    f95Assert(f95Denied(fn()=> $reader->read($command,$dirForeign)),
        'F1-96 another tenant private dossier cannot be displayed');
    f95Assert(f95Denied(fn()=> $reader->read($command,$base.'/missing-package')),
        'F1-96 missing disk package fails closed, no DB-only preview');

    $html2=$dir2.'/index.html';
    $original2=(string)file_get_contents($html2);
    file_put_contents($html2,$original2."\n");
    f95Assert(f95Denied(fn()=> $reader->read($command,$dir2)),
        'F1-96 altered post-persist HTML cannot be accepted as matching DB');
    file_put_contents($html2,$original2);

    $gate->allowed=false;
    f95Assert(f95Denied(fn()=> $reader->read($command,$dir2)),
        'F1-96 revoked fictional authority blocks even exact read-only replay');
    $gate->allowed=true;

    f95Assert(f95Denied(fn()=> (new Latest($admin,$gate))->read($command,$dir2)),
        'F1-96 privileged database role is never an allowed viewer');
    $app->beginTransaction();
    try {
        f95Assert(f95Denied(fn()=> $reader->read($command,$dir2)),
            'F1-96 caller-owned transaction is refused');
    } finally {
        $app->rollBack();
    }
    f95Assert(
        $reader->read($command,$dir2)===$latest &&
        f95DraftCount($app,'tenant-f95-a')===2 &&
        (int)$app->query('SELECT COUNT(*) FROM sites_drafts')->fetchColumn()===0,
        'F1-96 repeat verified read never mutates revisions or leaks tenant context');

    echo "F1-96 latest-readback negative checks included in F1-95 suite.\n";

    echo "\n$checks F1-95 verified private draft PostgreSQL18 assertions passed.\n";
} finally {
    putenv('SITES_F95_DRAFT_LEDGER_LAB');
    putenv('SITES_F96_LATEST_READBACK_LAB');
    foreach([$dir1,$dir2,$dirForeign] as $directory)f95Clean($directory);
    @rmdir($base);
}
echo "No customer IAM, production storage, publish action, API or delivery.\n";
