<?php
declare(strict_types=1);

use Imagine\Sites\Production\{PrivatePreviewHistory,PreviewRevisionConflict,SiteBrief};

if (!isset($admin,$connections,$workspaces,$companies,$ok,$count,$notesMigration,$notesSqlRefused) ||
    getenv('SITES_LOCAL_ROLE_CONTRACT') !== '1') {
    throw new RuntimeException('Review guidance requires the verified role fixture');
}
$feedbackChecksBefore=$count;
$feedbackProject='80000000-0000-4000-8000-000000000002';
$feedbackBrief=static fn(int $revision):SiteBrief=>new SiteBrief('onepage','Orientação fictícia',
    'Versão de orientação '.$revision,'Texto sintético para o ciclo de ajustes.','Conheça',['Benefício de exemplo']);
$feedbackFirst=$workspaces['a']['author']->createDemonstration($feedbackProject,$companies['a'],$feedbackBrief(1));
$feedbackHistory=static fn():array=>$admin->query(<<<'SQL'
SELECT
 (SELECT md5(string_agg(row_to_json(r)::text,'' ORDER BY project_id,revision,reviewer)) FROM sites_preview.reviews r) AS reviews,
 (SELECT md5(string_agg(row_to_json(a)::text,'' ORDER BY id)) FROM sites_preview.audit a) AS audit
SQL)->fetch(PDO::FETCH_ASSOC);
$feedbackRaw=$connections['a']['reviewer']->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?,?)');
$feedbackBefore=$feedbackHistory();
foreach ([null,''," \t\n\r\u{00A0}\u{2003}\u{3000}",str_repeat('漢',2001)] as $invalidNote) {
    $notesSqlRefused(static fn()=>$feedbackRaw->execute([
        $feedbackProject,$companies['a'],1,$feedbackFirst['sha256'],'changes_requested',$invalidNote,
    ]),'22023','actual SQL rejects empty, Unicode-white or oversized guidance');
}
$ok($feedbackHistory()===$feedbackBefore,'invalid SQL guidance creates neither review nor audit');
$feedbackNote="  Ajuste a chamada e explique o benefício.\nTexto literal <strong data-feedback-probe=\"literal\">sem formatação</strong> 漢😀  ";
$workspaces['a']['reviewer']->review($feedbackProject,$companies['a'],1,$feedbackFirst['sha256'],
    'changes_requested',$feedbackNote);
$feedbackReader=new PrivatePreviewHistory($connections['a']['reader']);
$feedbackPage=$feedbackReader->page($companies['a'],$feedbackProject);
$ok($feedbackPage['current_revision']===1 && $feedbackPage['current_sha256']===$feedbackFirst['sha256'] &&
    count($feedbackPage['current_reviews'])===1 && $feedbackPage['current_reviews'][0]['note']===$feedbackNote &&
    $feedbackPage['current_reviews'][0]['sha256']===$feedbackFirst['sha256'] &&
    $feedbackPage['current_reviews'][0]['decision']==='changes_requested',
    'current guidance retains exact Unicode, literal markup, borders and the reviewed digest');
$ok($feedbackPage['reviews'][0]['note']===$feedbackNote &&
    $feedbackPage['reviews'][0]['revision']===1 && $feedbackPage['current_reviews_truncated']===false,
    'version history and current guidance expose the same persisted note');
$feedbackBefore=$feedbackHistory();
try {
    $workspaces['a']['reviewer']->review($feedbackProject,$companies['a'],1,$feedbackFirst['sha256'],
        'approved_internal','Outra tentativa de parecer fictício');
    $ok(false,'duplicate reviewer unexpectedly overwrote guidance');
} catch (PreviewRevisionConflict) {
    $ok($feedbackHistory()===$feedbackBefore,'duplicate review returns a conflict and leaves original note/audit unchanged');
}
$feedbackSecond=$workspaces['a']['author']->revise($feedbackProject,$companies['a'],1,$feedbackBrief(2));
$feedbackNext=$feedbackReader->page($companies['a'],$feedbackProject);
$ok($feedbackNext['current_revision']===2 && $feedbackNext['current_reviews']===[] &&
    $feedbackNext['current_sha256']===$feedbackSecond['sha256'] &&
    $feedbackNext['reviews'][0]['note']===$feedbackNote,
    'new version retains historical guidance but inherits no current review');
$workspaces['a']['reviewer']->review($feedbackProject,$companies['a'],2,$feedbackSecond['sha256'],
    'approved_internal'," \n\u{2003}");
$feedbackApproved=$feedbackReader->page($companies['a'],$feedbackProject);
$ok($feedbackApproved['current_reviews'][0]['decision']==='approved_internal' &&
    $feedbackApproved['current_reviews'][0]['note']===null,'optional whitespace-only approval note is persisted as null');

for ($revision=3;$revision<=24;$revision++) {
    $feedbackLatest=$workspaces['a']['author']->revise($feedbackProject,$companies['a'],$revision-1,$feedbackBrief($revision));
}
$feedbackBoundary=str_repeat('😀',2000);
$workspaces['a']['reviewer']->review($feedbackProject,$companies['a'],24,$feedbackLatest['sha256'],
    'changes_requested',$feedbackBoundary);
$feedbackOldPage=$feedbackReader->page($companies['a'],$feedbackProject,3);
$ok(array_column($feedbackOldPage['versions'],'revision')===[2,1] &&
    $feedbackOldPage['current_revision']===24 && $feedbackOldPage['current_sha256']===$feedbackLatest['sha256'] &&
    $feedbackOldPage['current_reviews'][0]['revision']===24 &&
    $feedbackOldPage['current_reviews'][0]['sha256']===$feedbackLatest['sha256'] &&
    $feedbackOldPage['current_reviews'][0]['note']===$feedbackBoundary,
    '2000 Unicode codepoints are accepted and current guidance remains visible outside the history window');
$ok(array_column($feedbackOldPage['reviews'],'revision')===[2,1] &&
    $feedbackOldPage['reviews'][1]['note']===$feedbackNote &&
    $feedbackOldPage['brief']['headline']==='Versão de orientação 24',
    'historical notes stay in their original window while the edit briefing stays current');
$feedbackBefore=$feedbackHistory();
$feedbackPrevious=$workspaces['a']['reader']->view($feedbackProject,$companies['a'],23);
$feedbackCross=$connections['b']['reviewer']->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?,?)');
$notesSqlRefused(static fn()=>$feedbackCross->execute([
    $feedbackProject,$companies['a'],23,$feedbackPrevious['sha256'],'changes_requested','Nota de outra empresa',
]),'42501','another company cannot append guidance');
$notesSqlRefused(static fn()=>$feedbackRaw->execute([
    $feedbackProject,$companies['a'],23,$feedbackFirst['sha256'],'changes_requested','Digest de outra versão',
]),'42501','guidance cannot attach to a different version digest');
try {
    (new PrivatePreviewHistory($connections['b']['reader']))->page($companies['b'],$feedbackProject);
    $ok(false,'cross-company history unexpectedly visible');
} catch (OutOfBoundsException) {$ok(true,'another company cannot read review guidance');}
$ok($feedbackHistory()===$feedbackBefore,'cross-company and mismatched digest refusals preserve notes and audit');
$admin->exec("UPDATE sites_preview.principals SET revoked_at=clock_timestamp() WHERE database_login='lab486_a_reviewer'");
try {
    $notesSqlRefused(static fn()=>$feedbackRaw->execute([
        $feedbackProject,$companies['a'],23,$feedbackPrevious['sha256'],'changes_requested','Revogação fictícia',
    ]),'42501','revoked reviewer cannot append guidance');
} finally {$admin->exec("UPDATE sites_preview.principals SET revoked_at=NULL WHERE database_login='lab486_a_reviewer'");}
$feedbackBefore=$feedbackHistory();
foreach (['UPDATE sites_preview.reviews SET note=\'alterada\' WHERE note IS NOT NULL',
    'DELETE FROM sites_preview.reviews WHERE note IS NOT NULL'] as $sql) {
    try {$admin->exec($sql);$ok(false,'immutable guidance unexpectedly changed');}
    catch (PDOException $error) {$ok(str_contains($error->getMessage(),'Preview history is append-only'),
        'existing immutable trigger protects review guidance');}
}
$refused=false;
try {$notesMigration->down();}catch(RuntimeException $error){
    $refused=$error->getMessage()==='Preserve preview review notes; recovery review required';
}
$ok($refused && $feedbackHistory()===$feedbackBefore,
    'populated-note rollback is refused specifically and preserves the full review/audit history');
$legacyPage=(new PrivatePreviewHistory($legacyDbs['reviewer']))->page($legacyCompany,$legacyProject);
$ok($legacyPage['reviews'][1]['revision']===1 && $legacyPage['reviews'][1]['decision']==='changes_requested' &&
    $legacyPage['reviews'][1]['note']===null,'legacy adjustment request remains readable without invented guidance');
echo 'REVIEW GUIDANCE SQL PASS: '.($count-$feedbackChecksBefore).
    " assertions; exact version/digest, legacy history, Unicode, current-head guidance across pages, CAS, isolation and immutable notes\n";
