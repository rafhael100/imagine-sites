<?php

declare(strict_types=1);

// Extends the complete custody + reviewer journal suites in one disposable PG18.
if (getenv('SITES_LANDING_READBACK_CI') !== '1' ||
    getenv('SITES_LANDING_REVIEW_CI') !== '1' ||
    getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('LANDING_READBACK_FIXTURE_CI_ONLY');
}
require __DIR__.'/landing-page-pg18-private-review-journal.php';
require_once __DIR__.'/../apps/sites/app/Integration/PortalLandingPageReviewReadbackCandidate.php';

use App\Integration\PortalLandingPageReviewReadbackCandidate;

$admin->exec(file_get_contents(
    __DIR__.'/../contracts/portal-sites/candidates/landing-private-review-readback-pg18.sql'
));
foreach (['reader_a', 'reader_b', 'reader_unbound'] as $who) {
    $admin->exec("CREATE ROLE sites_landing_ci_$who LOGIN NOSUPERUSER NOBYPASSRLS PASSWORD 'ci-only-$who'");
    $admin->exec("GRANT USAGE ON SCHEMA sites_landing_custody TO sites_landing_ci_$who");
    $admin->exec("GRANT EXECUTE ON FUNCTION sites_landing_custody.read_current_review_observation(text,text) TO sites_landing_ci_$who");
}
foreach (['reader_a'=>'tenant-A', 'reader_b'=>'tenant-B'] as $who=>$tenant) {
    $admin->prepare('INSERT INTO sites_landing_custody.review_readers(login_name,tenant_ref,active) VALUES(?,?,true)')
        ->execute(["sites_landing_ci_$who", $tenant]);
}
$readerAConnection = $login('reader_a');
$readerBConnection = $login('reader_b');
$readerUnboundConnection = $login('reader_unbound');
$readerA = new PortalLandingPageReviewReadbackCandidate($readerAConnection, $draft);
$readerB = new PortalLandingPageReviewReadbackCandidate($readerBConnection, $draft);
$readerUnbound = new PortalLandingPageReviewReadbackCandidate($readerUnboundConnection, $draft);
$state->version=5;
$state->cutoff=null;
$state->calls=0;
$state->revoked=false;
$admin->exec("UPDATE sites_landing_custody.admitted_sites SET active=true WHERE tenant_ref='tenant-A'");
$packetA5=$packet('tenant-A',5);

$read=static function(PortalLandingPageReviewReadbackCandidate $reader,string $tenant,
                     array $pointer,int $version)use($site,$state):array {
    return $reader->readCurrent(
        $tenant,'order-A',$site,'contract-A',7,$version,
        "approval-$version",$version,hash('sha256',$state->bytes),$pointer['reviewSha256'],
    );
};
$checksRead=0;
$checkRead=static function(bool $true,string $label)use(&$checksRead):void {
    if (!$true) throw new RuntimeException('LANDING READBACK CI FAILURE: '.$label);
    ++$checksRead;
};
$denyRead=static function(callable $action,string $label)use($checkRead):void {
    ob_start();$denied=false;
    try{$action();}catch(RuntimeException $e){
        $denied=$e->getMessage()==='SITES_LANDING_REVIEW_READBACK_DENIED'
            && $e->getPrevious()===null;
    }
    $output=ob_get_clean();
    $checkRead($denied && $output==='', $label);
};
$checkRead($readerAConnection->query('SELECT session_user')->fetchColumn()==='sites_landing_ci_reader_a',
    'readback session is separate from artifact author and reviewer');
$denyRead(static fn()=>$read($readerA,'tenant-A',$packetA5,5),
    'no observation means no review-readback authority');
$checkRead($countReview()===3,'reader denial leaves immutable journal unchanged');
$checkRead($observe($journalA,'review-A5-final','tenant-A',$packetA5,5)['state']
    ==='observation_private_only', 'independently admitted reviewer records current version');
$checkRead($countReview()===4,'one new private journal entry');
$rA=$read($readerA,'tenant-A',$packetA5,5);
$checkRead($rA===[
    'state'=>'review_observation_private_only',
    'decision'=>'observations_recorded',
    'reasonCode'=>'content',
    'operationRef'=>'review-A5-final',
    'reviewSha256'=>$packetA5['reviewSha256'],
], 'readback returns exact non-authorizing decision and no HTML');
$checkRead($read($readerA,'tenant-A',$packetA5,5)===$rA,
    'repeat read is stable and has no write side effects');
$checkRead($countReview()===4,'reads do not create journal rows');

 // A readback witness must not escape while any of its independently
 // admitted identities/site is being revoked by another PG18 session.
 // A timeout must fail closed, roll back and preserve the private journal.
$revocationLocks=[
    'reader'=>"SELECT login_name FROM sites_landing_custody.review_readers WHERE login_name='sites_landing_ci_reader_a' FOR UPDATE",
    'site'=>"SELECT site_ref FROM sites_landing_custody.admitted_sites WHERE tenant_ref='tenant-A' AND site_ref='$site' FOR UPDATE",
    'author'=>"SELECT login_name FROM sites_landing_custody.principals WHERE login_name='sites_landing_ci_a' FOR UPDATE",
    'reviewer'=>"SELECT login_name FROM sites_landing_custody.reviewers WHERE login_name='sites_landing_ci_reviewer_a' FOR UPDATE",
];
foreach($revocationLocks as $identity=>$statement){
    $admin->beginTransaction();
    $locked=$admin->query($statement)->fetchColumn();
    $checkRead(is_string($locked) && $locked!=='', "independent $identity row can be held before revocation");
    $readerAConnection->exec("SET lock_timeout='300ms'");
    $denyRead(static fn()=>$read($readerA,'tenant-A',$packetA5,5),
        "review readback refuses pending $identity revocation");
    $readerAConnection->exec('RESET lock_timeout');
    $admin->rollBack();
    $checkRead(!$readerAConnection->inTransaction() && $countReview()===4,
        "pending $identity revocation left no reader transaction or journal mutation");
    $checkRead($read($readerA,'tenant-A',$packetA5,5)===$rA,
        "readback recovers after independent $identity lock is released");
}

$denyRead(static fn()=>$read($readerB,'tenant-A',$packetA5,5),
    'B connection cannot cross tenant even with correct A pointer');
$denyRead(static fn()=>$read($readerUnbound,'tenant-A',$packetA5,5),
    'unregistered reader denied despite EXECUTE permission');
$denyRead(static fn()=>$read($readerA,'tenant-A',[
    'reviewSha256'=>str_repeat('0',64)
],5), 'mismatched digest denied');
$deniedDirect=false;
try{$readerAConnection->query('SELECT * FROM sites_landing_custody.review_observations');}
catch(PDOException){$deniedDirect=true;}
$checkRead($deniedDirect,'reader has no direct SELECT on journal');
$deniedDirect=false;
try{$readerAConnection->query('SELECT * FROM sites_landing_custody.pending_artifacts');}
catch(PDOException){$deniedDirect=true;}
$checkRead($deniedDirect,'reader cannot access private HTML');
$deniedDirect=false;
try{$reviewA->query('SELECT * FROM sites_landing_custody.read_current_review_observation('.
    "'$site','".$packetA5['reviewSha256']."')");}
catch(PDOException){$deniedDirect=true;}
$checkRead($deniedDirect,'reviewer has no readback function grant');
$admin->exec("UPDATE sites_landing_custody.reviewers SET active=false WHERE login_name='sites_landing_ci_reviewer_a'");
$denyRead(static fn()=>$read($readerA,'tenant-A',$packetA5,5),
    'revoked reviewer admission invalidates current witness');
$admin->exec("UPDATE sites_landing_custody.reviewers SET active=true WHERE login_name='sites_landing_ci_reviewer_a'");
$admin->exec("UPDATE sites_landing_custody.review_readers SET active=false WHERE login_name='sites_landing_ci_reader_a'");
$denyRead(static fn()=>$read($readerA,'tenant-A',$packetA5,5),
    'revoked reader denied immediately');
$admin->exec("UPDATE sites_landing_custody.review_readers SET active=true WHERE login_name='sites_landing_ci_reader_a'");
$admin->exec("UPDATE sites_landing_custody.admitted_sites SET active=false WHERE tenant_ref='tenant-A'");
$denyRead(static fn()=>$read($readerA,'tenant-A',$packetA5,5),
    'revoked independent site admission invalidates readback');
$admin->exec("UPDATE sites_landing_custody.admitted_sites SET active=true WHERE tenant_ref='tenant-A'");
$checkRead($read($readerA,'tenant-A',$packetA5,5)===$rA,
    'fresh re-admission enables same immutable witness');
$state->calls=0;$state->cutoff=3;
$denyRead(static fn()=>$read($readerA,'tenant-A',$packetA5,5),
    'Portal revocation observed after SQL read denied before returning');
$state->cutoff=null;$state->calls=0;
$checkRead(!$readerAConnection->inTransaction(),
    'post-read rejection rolls back read-only transaction');
$state->version=3;
$packetB=$packet('tenant-B',3);
$rB=$read($readerB,'tenant-B',$packetB,3);
$checkRead($rB['decision']==='changes_requested' && $rB['reasonCode']==='identity'
    && $rB['state']==='review_observation_private_only',
    'B tenant changes-requested event remains non-approving');
$denyRead(static fn()=>$read($readerA,'tenant-B',$packetB,3),
    'A reader cannot access same UUID in B tenant');
$state->version=6;
$run($a,'operation-A-v6');
$packetA6=$packet('tenant-A',6);
$denyRead(static fn()=>$read($readerA,'tenant-A',$packetA5,5),
    'superseded custody revision cannot reuse previous observation');
$denyRead(static fn()=>$read($readerA,'tenant-A',$packetA6,6),
    'new revision without independent observation fails closed');
$checkRead($countReview()===4,'supersession preserves prior journal and adds no review');
$checkRead(!$readerAConnection->inTransaction() &&
    !$readerBConnection->inTransaction() && !$readerUnboundConnection->inTransaction(),
    'all reader transactions ended cleanly');
echo "SITES LANDING PG18 REVIEW READBACK PASS: $checksRead checks; private evidence only, NO APPROVAL OR RELEASE.\n";
