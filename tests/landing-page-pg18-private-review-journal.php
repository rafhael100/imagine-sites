<?php

declare(strict_types=1);

// Synthetic, disposable PostgreSQL 18: no real reviewer, approval or release.
// The preceding fixture stages exact HTML in the same ephemeral test database.
if (getenv('SITES_LANDING_REVIEW_CI') !== '1' ||
    getenv('SITES_LANDING_CUSTODY_CI') !== '1' ||
    getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('LANDING_REVIEW_FIXTURE_CI_ONLY');
}
require __DIR__.'/landing-page-pg18-private-custody.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalLandingPageReviewJournalCandidate.php';

use App\Integration\PortalLandingPageReviewJournalCandidate;

$admin->exec(file_get_contents(
    __DIR__.'/../contracts/portal-sites/candidates/landing-private-review-journal-pg18.sql'
));
foreach (['reviewer_a','reviewer_b','reviewer_unbound'] as $who) {
    $admin->exec("CREATE ROLE sites_landing_ci_$who LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD 'ci-only-$who'");
    $admin->exec("GRANT USAGE ON SCHEMA sites_landing_custody TO sites_landing_ci_$who");
    $admin->exec("GRANT EXECUTE ON FUNCTION sites_landing_custody.record_review_observation(text,text,text,text,text) TO sites_landing_ci_$who");
}
foreach (['reviewer_a'=>'tenant-A','reviewer_b'=>'tenant-B'] as $who=>$tenant) {
    $admin->prepare('INSERT INTO sites_landing_custody.reviewers(login_name,tenant_ref,active) VALUES(?,?,true)')
        ->execute(["sites_landing_ci_$who",$tenant]);
}
$reviewA=$login('reviewer_a');
$reviewB=$login('reviewer_b');
$reviewUnbound=$login('reviewer_unbound');
$journalA=new PortalLandingPageReviewJournalCandidate($reviewA,$draft);
$journalB=new PortalLandingPageReviewJournalCandidate($reviewB,$draft);
$journalUnbound=new PortalLandingPageReviewJournalCandidate($reviewUnbound,$draft);
$admin->exec("UPDATE sites_landing_custody.admitted_sites SET active=true WHERE tenant_ref='tenant-A'");
$state->version=3;
$state->calls=0;
$state->cutoff=null;
$state->revoked=false;
$run($a,'operation-A-v3');
$run($b,'operation-B-v3','tenant-B');
$packet=static fn(string $tenant,int $version): array => $draft->prepareForInternalReview(
    $tenant,'order-A',$site,'contract-A',7,$version,
    "approval-$version",$version,hash('sha256',$state->bytes)
);
$packetA=$packet('tenant-A',3);
$packetB=$packet('tenant-B',3);
$observe=static function(
    PortalLandingPageReviewJournalCandidate $app, string $operation,
    string $tenant, array $p, int $version,
    string $decision='observations_recorded', string $reason='content',
) use($site,$state):array {
    return $app->observe(
        $operation,$tenant,'order-A',$site,'contract-A',7,
        $version,"approval-$version",$version,hash('sha256',$state->bytes),
        $p['reviewSha256'],$decision,$reason,
    );
};
$countReview=static fn():int => (int)$admin->query(
    'SELECT count(*) FROM sites_landing_custody.review_observations'
)->fetchColumn();
$checksReview=0;
$expect=static function(bool $v,string $label) use (&$checksReview):void {
    if (!$v) throw new RuntimeException('LANDING REVIEW CI FAILURE: '.$label);
    ++$checksReview;
};
$denyReview=static function(callable $fn,string $label) use ($expect):void {
    ob_start();$denied=false;
    try {$fn();} catch (RuntimeException $e) {
        $denied=$e->getMessage()==='SITES_LANDING_REVIEW_JOURNAL_DENIED' &&
            $e->getPrevious()===null;
    }
    $captured=ob_get_clean();
    $expect($denied && $captured==='',$label);
};
$expect($reviewA->query('select session_user')->fetchColumn()==='sites_landing_ci_reviewer_a',
    'reviewer A has independent PostgreSQL login');
$expect($countReview()===0,'empty immutable observation journal');
// The reviewer must serialize with custody on the same tenant/site row.
// A row held by an independent transaction cannot be bypassed by a
// correctly admitted reviewer, even with an otherwise valid pointer.
$admin->beginTransaction();
$lockedReviewSite=$admin->prepare(
    "SELECT 1 FROM sites_landing_custody.admitted_sites
      WHERE tenant_ref='tenant-A' AND site_ref=? FOR UPDATE"
);
$lockedReviewSite->execute([$site]);
$lockedReviewSite->closeCursor();
$reviewA->exec("SET lock_timeout='300ms'");
$denyReview(static fn()=>$observe($journalA,'review-A3-lock-blocked','tenant-A',$packetA,3),
    'review cannot race an independent custody admission row lock');
$reviewA->exec('RESET lock_timeout');
$admin->rollBack();
$expect($countReview()===0,'review lock timeout leaves append-only journal empty');
$result=$observe($journalA,'review-A3','tenant-A',$packetA,3);
$expect($result['state']==='observation_private_only' &&
    $result['reviewSha256']===$packetA['reviewSha256'],
    'recorded non-authorizing observation on exact artifact');
$expect($countReview()===1,'one journal row persisted');
$row=$admin->query("SELECT * FROM sites_landing_custody.review_observations WHERE tenant_ref='tenant-A'")->fetch(PDO::FETCH_ASSOC);
$expect($row['observed_by']==='sites_landing_ci_reviewer_a' &&
    $row['artifact_sha256']===$packetA['artifactSha256'] &&
    $row['review_sha256']===$packetA['reviewSha256'],
    'audit binds separate login and exact private review and artifact digest');
$expect($observe($journalA,'review-A3','tenant-A',$packetA,3)===$result &&
    $countReview()===1,'identical replay is idempotent');
$denyReview(static fn()=>$observe($journalA,'review-A3','tenant-A',$packetA,3,'changes_requested'),
    'same operation with different decision denied');
$denyReview(static fn()=>$observe($journalA,'review-A3','tenant-A',$packetA,3,'observations_recorded','other'),
    'same operation with different reason denied');
$denyReview(static fn()=>$observe($journalA,'review-A3-new-op','tenant-A',$packetA,3),
    'one journal observation per source version');
$denyReview(static fn()=>$observe($journalB,'review-B-on-A','tenant-A',$packetA,3),
    'cross-tenant reviewer cannot observe tenant A');
$denyReview(static fn()=>$observe($journalUnbound,'review-unbound','tenant-A',$packetA,3),
    'unregistered reviewer denied despite EXECUTE grant');
$expect($countReview()===1,'denied attempts do not change journal');
$noSelect=false;
try {$reviewA->query('SELECT * FROM sites_landing_custody.review_observations');}
catch(PDOException) {$noSelect=true;}
$expect($noSelect,'no direct private journal SELECT privilege');
$noWriterReview=false;
try {$dbA->prepare('SELECT sites_landing_custody.record_review_observation(?,?,?,?,?)')
    ->execute(['writer-review',$site,$packetA['reviewSha256'],'observations_recorded','content']);}
catch(PDOException) {$noWriterReview=true;}
$expect($noWriterReview,'artifact writer role cannot record reviewer observation');
$expect($observe($journalB,'review-B3','tenant-B',$packetB,3,'changes_requested','identity')['state']
    ==='observation_private_only','other tenant can log private changes requested');
$expect($countReview()===2,'both tenant journals isolated');
$state->version=4;
$run($a,'operation-A-v4');
$packetA4=$packet('tenant-A',4);
$denyReview(static fn()=>$observe($journalA,'review-A-stale','tenant-A',$packetA,3),
    'old source head rejected after new custody revision');
$expect($observe($journalA,'review-A4','tenant-A',$packetA4,4,'changes_requested','other')['state']
    ==='observation_private_only','new revision creates independent observation');
$expect($countReview()===3,'supersession does not rewrite old journal');
$state->version=5;
$run($a,'operation-A-v5');
$packetA5=$packet('tenant-A',5);
$state->calls=0;$state->cutoff=5;
$denyReview(static fn()=>$observe($journalA,'review-A5-drift','tenant-A',$packetA5,5),
    'Portal head revoked between SQL insertion and commit rolls back');
$state->cutoff=null;$state->calls=0;
$expect($countReview()===3 && !$reviewA->inTransaction(),
    'revoked readback left no durable event or open transaction');
$admin->exec("UPDATE sites_landing_custody.reviewers SET active=false WHERE login_name='sites_landing_ci_reviewer_a'");
$denyReview(static fn()=>$observe($journalA,'review-A5-inactive','tenant-A',$packetA5,5),
    'disabled independently registered reviewer denied');
$admin->exec("UPDATE sites_landing_custody.reviewers SET active=true WHERE login_name='sites_landing_ci_reviewer_a'");
$admin->exec("UPDATE sites_landing_custody.admitted_sites SET active=false WHERE tenant_ref='tenant-A'");
$denyReview(static fn()=>$observe($journalA,'review-A5-site-revoked','tenant-A',$packetA5,5),
    'revoked independent site admission denied');
$expect($countReview()===3,'revocations add no journal entry');
$noMutation=false;
try {$admin->exec("UPDATE sites_landing_custody.review_observations SET reason_code='other'");}
catch(PDOException) {$noMutation=true;}
$expect($noMutation,'immutable audit rejects even admin update');
$expect(!$reviewA->inTransaction() && !$reviewB->inTransaction(),
    'reviewer sessions close transactions');
echo "SITES LANDING PG18 PRIVATE REVIEW JOURNAL PASS: $checksReview checks; synthetic identities, NO RELEASE AUTHORITY.\n";
