<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Imagine\Sites\Production\{SiteBrief,StaticSiteRenderer};

// Included only by the parent after its disposable cluster identity checks.
if (!isset($admin,$connection,$password,$ok,$count,$connections) ||
    getenv('SITES_LOCAL_ROLE_CONTRACT') !== '1') {
    throw new RuntimeException('Review note upgrade requires the verified role fixture');
}
$upgradeChecksBefore=$count;
$legacyCompany='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$legacyProject='80000000-0000-4000-8000-000000000001';
$legacyDbs=[];
foreach (['author','reviewer'] as $profile) {
    $role='lab486_legacy_'.$profile;
    $admin->exec("CREATE ROLE $role LOGIN PASSWORD ".$admin->quote($password).
        ' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
    $admin->exec("GRANT USAGE ON SCHEMA sites_preview TO $role;
        GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $role;
        GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text) TO $role");
    if ($profile==='author') $admin->exec("GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $role");
    else $admin->exec("GRANT EXECUTE ON FUNCTION sites_preview.review_version(uuid,uuid,integer,text,text) TO $role");
    $statement=$admin->prepare("INSERT INTO sites_preview.principals
        VALUES(?,?::uuid,?::boolean,?::boolean,clock_timestamp()+interval '1 hour',NULL)");
    $statement->execute([$role,$legacyCompany,$profile==='author'?'true':'false',$profile==='reviewer'?'true':'false']);
    $legacyDbs[$profile]=new PDO('pgsql:host=127.0.0.1;port=55448;dbname=postgres',$role,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
}
$legacyBrief=new SiteBrief('onepage','Histórico fictício','Revisão anterior à orientação',
    'Registro sintético que precisa sobreviver ao upgrade.','Conheça',['Exemplo anterior']);
$legacyArtifact=(new StaticSiteRenderer())->render($legacyBrief);
$legacyHtml=preg_replace('/(<body[^>]*>)/',
    '$1<aside role="note" style="background:#F9941D;color:#222;padding:16px;text-align:center">DEMONSTRAÇÃO PRIVADA — sem contratação, pagamento ou aceite.</aside>',
    $legacyArtifact->html,1);
$legacyDigest=hash('sha256',$legacyHtml);
$statement=$legacyDbs['author']->prepare('INSERT INTO sites_preview.projects(id,company_id,product) VALUES(?::uuid,?::uuid,?)');
$statement->execute([$legacyProject,$legacyCompany,'onepage']);
$legacyVersion=$legacyDbs['author']->prepare('INSERT INTO sites_preview.versions
    (project_id,company_id,revision,brief,html,sha256) VALUES(?::uuid,?::uuid,?,?::jsonb,?,?)');
foreach ([1,2] as $revision) $legacyVersion->execute([$legacyProject,$legacyCompany,$revision,
    json_encode($legacyBrief,JSON_THROW_ON_ERROR),$legacyHtml,$legacyDigest]);
$legacyReview=$legacyDbs['reviewer']->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?)');
$legacyReview->execute([$legacyProject,$legacyCompany,1,$legacyDigest,'changes_requested']);
$legacyHistory=static fn():array=>$admin->query(<<<'SQL'
SELECT
 (SELECT md5(string_agg((to_jsonb(r)-'note')::text,'' ORDER BY project_id,revision,reviewer)) FROM sites_preview.reviews r) AS reviews,
 (SELECT md5(string_agg(row_to_json(a)::text,'' ORDER BY id)) FROM sites_preview.audit a) AS audit,
 (SELECT md5(string_agg(row_to_json(v)::text,'' ORDER BY project_id,revision)) FROM sites_preview.versions v) AS versions
SQL)->fetch(PDO::FETCH_ASSOC);
$legacyBefore=$legacyHistory();
$oldFunction=$admin->query("SELECT oid,proowner,proacl::text AS acl FROM pg_proc
    WHERE oid='sites_preview.review_version(uuid,uuid,integer,text,text)'::regprocedure")->fetch(PDO::FETCH_ASSOC);
$notesMigration=require __DIR__.'/../../../apps/sites/database/migrations/2026_11_18_000008_add_private_preview_review_notes.php';
$applyNotes=static function()use($admin,$notesMigration):void {
    $admin->exec('SET ROLE lab486_owner');
    try{$notesMigration->up();}finally{$admin->exec('RESET ROLE');}
};
$applyNotes();
$ok($legacyHistory()===$legacyBefore,'upgrade preserves all legacy version, review and audit bytes');
$ok($admin->query('SELECT note FROM sites_preview.reviews')->fetchColumn()===null,
    'legacy changes requested receives no invented note');
$ok($admin->query("SELECT oid,proowner,proacl::text AS acl FROM pg_proc
    WHERE oid='sites_preview.review_version(uuid,uuid,integer,text,text)'::regprocedure")->fetch(PDO::FETCH_ASSOC)===$oldFunction,
    'five-argument API retains its OID, owner and grants');
foreach ($connections as $roles) foreach ($roles as $profile=>$pdo) {
    $ok($pdo->query("SELECT has_function_privilege(current_user,
        'sites_preview.review_version(uuid,uuid,integer,text,text,text)','EXECUTE')")->fetchColumn()===($profile==='reviewer'),
        'upgrade copies only the existing reviewer execute capability');
}
$notesSqlRefused=static function(callable $work,string $state,string $label)use($ok):void {
    try{$work();}catch(PDOException $error){$ok($error->getCode()===$state,$label.' exact SQLSTATE');return;}
    $ok(false,$label.' unexpectedly succeeded');
};
$notesSqlRefused(static fn()=>$legacyReview->execute([$legacyProject,$legacyCompany,2,$legacyDigest,'changes_requested']),
    '22023','legacy API cannot create a new adjustment request without guidance');
$newReview=$legacyDbs['reviewer']->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?,?)');
$notesSqlRefused(static fn()=>$newReview->execute([$legacyProject,$legacyCompany,2,$legacyDigest,'changes_requested',null]),
    '22023','new API also requires guidance for adjustments');
$ok($legacyHistory()===$legacyBefore,'rejected blank guidance appends neither review nor audit');
$legacyReview->execute([$legacyProject,$legacyCompany,2,$legacyDigest,'approved_internal']);
$ok((int)$admin->query('SELECT count(*) FROM sites_preview.reviews WHERE note IS NULL')->fetchColumn()===2,
    'legacy five-argument approval remains compatible with optional guidance');
$beforeRollback=$legacyHistory();
$notesMigration->down();
$ok($legacyHistory()===$beforeRollback && $admin->query("SELECT to_regprocedure(
    'sites_preview.review_version(uuid,uuid,integer,text,text,text)')")->fetchColumn()===null,
    'note-free migration rollback preserves legacy history and removes only the new API');
$applyNotes();
$ok($legacyHistory()===$beforeRollback,'reapplying review notes preserves existing immutable history');
$notesLegacyCounts=['versions'=>2,'reviews'=>2,'audit'=>5];
echo 'REVIEW NOTES UPGRADE PASS: '.($count-$upgradeChecksBefore).
    " assertions; real populated migration, legacy bytes and grants preserved, missing guidance refused, empty-note rollback and reapply\n";
