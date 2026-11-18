<?php

declare(strict_types=1);

use Imagine\Sites\Production\PrivatePreviewWorkspace;
use Imagine\Sites\Production\SiteBrief;

// Shared only by the guarded disposable SQL and HTTP fixtures. Nothing is
// seeded on import, and no application route loads this file.
return static function (PDO $rpAdmin, array $rpAuthors, callable $rpConnect, string $rpPassword, string $rpPrefix): array {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || strlen($rpPassword) < 32 ||
        !in_array($rpPrefix, ['lab511_rp_', 'sites_preview_rp511_'], true)) throw new RuntimeException('Review pagination seed scope denied');
    $rpIdentity = $rpAdmin->query("SELECT current_database() AS db,session_user::text AS login,current_user::text AS actor,
        host(inet_server_addr()) AS address,inet_server_port() AS port,current_setting('data_directory') AS directory,
        current_setting('server_version_num')::int AS version,(SELECT system_identifier::text FROM pg_control_system()) AS cluster,
        (SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) AS tls")->fetch(PDO::FETCH_ASSOC);
    $rpLocal = $rpPrefix === 'lab511_rp_' && getenv('SITES_LOCAL_ROLE_CONTRACT') === '1' &&
        $rpIdentity['db'] === 'postgres' && $rpIdentity['login'] === getenv('ROLE_CONTRACT_LOGIN') && $rpIdentity['address'] === '127.0.0.1' &&
        (int) $rpIdentity['port'] === 55448 && $rpIdentity['cluster'] === getenv('ROLE_CONTRACT_CLUSTER') &&
        realpath($rpIdentity['directory']) === realpath((string) getenv('ROLE_CONTRACT_PGDATA'));
    $rpHttp = $rpPrefix === 'sites_preview_rp511_' && getenv('SITES_PREVIEW_HTTP_CI') === '1' &&
        $rpIdentity['db'] === 'sites_preview_http_ci' && $rpIdentity['login'] === 'postgres' && (int) $rpIdentity['port'] === 5432 &&
        $rpIdentity['cluster'] === getenv('PREVIEW_EXPECTED_CLUSTER') && $rpIdentity['tls'] === true;
    if ((!$rpLocal && !$rpHttp) || $rpIdentity['actor'] !== $rpIdentity['login'] || $rpIdentity['version'] < 180000 ||
        $rpIdentity['version'] >= 190000 || $rpAdmin->inTransaction()) throw new RuntimeException('Verified disposable pagination target required');
    $rpCompanies = ['a' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'b' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'];
    $rpProjects = ['a' => 'a1100000-0000-4000-8000-000000000001', 'empty' => 'a1100000-0000-4000-8000-000000000002',
        'b' => 'b1100000-0000-4000-8000-000000000001'];
    $rpWorkspaces = [];
    foreach ($rpCompanies as $rpKey => $rpCompany) {
        if (!(($rpAuthors[$rpKey] ?? null) instanceof PDO)) throw new RuntimeException('Native synthetic author required');
        $rpExpectedLogin = $rpLocal ? 'lab486_'.$rpKey.'_author' : ($rpKey === 'a' ? 'sites_preview_author' : 'sites_preview_other');
        if ($rpAuthors[$rpKey]->query('SELECT session_user::text')->fetchColumn() !== $rpExpectedLogin) throw new RuntimeException('Native synthetic author identity mismatch');
        $rpWorkspaces[$rpKey] = new PrivatePreviewWorkspace($rpAuthors[$rpKey]); $rpWorkspaces[$rpKey]->assertSafeRuntime();
        $rpAccess = $rpAuthors[$rpKey]->prepare("SELECT sites_preview.can_access(?::uuid,'edit') AND NOT sites_preview.can_access(?::uuid,'edit')");
        $rpAccess->execute([$rpCompany, $rpCompanies[$rpKey === 'a' ? 'b' : 'a']]);
        if ($rpAccess->fetchColumn() !== true) throw new RuntimeException('Bound independent synthetic authors required');
    }
    $rpExisting = $rpAdmin->prepare('SELECT count(*) FROM sites_preview.projects WHERE id IN (?::uuid,?::uuid,?::uuid)');
    $rpExisting->execute(array_values($rpProjects));
    $rpExistingRoles = $rpAdmin->prepare('SELECT count(*) FROM pg_roles WHERE starts_with(rolname::text,?)');
    $rpExistingRoles->execute([$rpPrefix]);
    if ((int) $rpExisting->fetchColumn() !== 0 || (int) $rpExistingRoles->fetchColumn() !== 0) throw new RuntimeException('Fresh synthetic review pagination scope required');
    $rpReviewers = $rpReviewerDbs = $rpRoles = [];
    for ($rpNumber = 1; $rpNumber <= 21; ++$rpNumber) {
        $rpRole = $rpPrefix.'a_'.str_pad((string) $rpNumber, 2, '0', STR_PAD_LEFT); $rpRoles[$rpNumber] = $rpRole;
        $rpAdmin->exec('CREATE ROLE '.$rpRole.' LOGIN PASSWORD '.$rpAdmin->quote($rpPassword).' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT');
        $rpAdmin->exec('GRANT USAGE ON SCHEMA sites_preview TO '.$rpRole.'; GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO '.$rpRole.
            '; GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text),sites_preview.review_version(uuid,uuid,integer,text,text,text) TO '.$rpRole);
        $rpPrincipal = $rpAdmin->prepare("INSERT INTO sites_preview.principals(database_login,company_id,can_edit,can_review,expires_at,revoked_at) VALUES(?,?::uuid,false,true,clock_timestamp()+interval '1 hour',NULL)");
        $rpPrincipal->execute([$rpRole, $rpCompanies['a']]);
        $rpReviewerDbs[$rpNumber] = $rpConnect($rpRole);
        if ($rpReviewerDbs[$rpNumber]->query('SELECT session_user::text')->fetchColumn() !== $rpRole) throw new RuntimeException('Native reviewer identity mismatch');
        $rpReviewers[$rpNumber] = new PrivatePreviewWorkspace($rpReviewerDbs[$rpNumber]); $rpReviewers[$rpNumber]->assertSafeRuntime();
    }
    $rpBrief = static fn (int $rpRevision): SiteBrief => new SiteBrief('onepage', 'Pareceres CI', 'Pareceres CI versão '.$rpRevision,
        'Conteúdo fictício para consultar todos os pareceres preservados.', 'Consultar', ['Histórico independente da página']);
    $rpDigests = [];
    for ($rpRevision = 1; $rpRevision <= 20; ++$rpRevision) {
        $rpVersion = $rpRevision === 1
            ? $rpWorkspaces['a']->createDemonstration($rpProjects['a'], $rpCompanies['a'], $rpBrief($rpRevision))
            : $rpWorkspaces['a']->revise($rpProjects['a'], $rpCompanies['a'], $rpRevision - 1, $rpBrief($rpRevision));
        if ($rpVersion['revision'] !== $rpRevision) throw new RuntimeException('Exact native preview version required');
        $rpDigests[$rpRevision] = $rpVersion['sha256'];
        for ($rpNumber = 1; $rpNumber <= 6; ++$rpNumber) {
            $rpNote = $rpNumber === 1 ? null : 'Parecer API '.str_pad((string) $rpNumber, 2, '0', STR_PAD_LEFT).' da versão '.$rpRevision.'.';
            $rpReviewers[$rpNumber]->review($rpProjects['a'], $rpCompanies['a'], $rpRevision, $rpDigests[$rpRevision], 'approved_internal', $rpNote);
        }
    }
    $rpWorkspaces['a']->createDemonstration($rpProjects['empty'], $rpCompanies['a'], new SiteBrief('onepage', 'Sem pareceres CI',
        'Versão própria ainda sem parecer', 'Texto fictício.', 'Consultar', ['Consulta vazia válida']));
    $rpWorkspaces['b']->createDemonstration($rpProjects['b'], $rpCompanies['b'], new SiteBrief('landing_page', 'Outra empresa CI',
        'Versão própria da empresa B', 'Texto fictício independente.', 'Consultar', ['Isolamento de empresa']));
    $rpTimeline = $rpAdmin->prepare("SELECT count(*) AS total,count(DISTINCT revision) AS revisions,
        min(created_at) FILTER(WHERE revision=1)-interval '1 second' AS tie_at FROM sites_preview.reviews WHERE project_id=?::uuid AND company_id=?::uuid");
    $rpTimeline->execute([$rpProjects['a'], $rpCompanies['a']]); $rpOriginal = $rpTimeline->fetch(PDO::FETCH_ASSOC);
    if ((int) $rpOriginal['total'] !== 120 || (int) $rpOriginal['revisions'] !== 20 || !is_string($rpOriginal['tie_at'])) throw new RuntimeException('Twenty native six-review groups required');
    $rpAclBefore = $rpAdmin->query("SELECT relacl::text FROM pg_class WHERE oid='sites_preview.reviews'::regclass")->fetchColumn();
    for ($rpNumber = 7; $rpNumber <= 21; ++$rpNumber) {
        $rpDb = $rpReviewerDbs[$rpNumber]; $rpRole = $rpRoles[$rpNumber];
        $rpScope = $rpDb->prepare("SELECT sites_preview.can_access(?::uuid,'review') AND EXISTS(SELECT 1 FROM sites_preview.versions
            WHERE project_id=?::uuid AND company_id=?::uuid AND revision=1 AND sha256=? AND author<>session_user)");
        $rpScope->execute([$rpCompanies['a'], $rpProjects['a'], $rpCompanies['a'], $rpDigests[1]]);
        if ($rpScope->fetchColumn() !== true) throw new RuntimeException('Exact bound synthetic reviewer/version required');
        $rpReviewers[$rpNumber]->assertSafeRuntime();
        // This is explicitly synthetic ordering state, not proof that the
        // review API accepts a caller-supplied timestamp. Native session_user,
        // FK/notes checks, FORCE RLS and the audit trigger remain in force.
        $rpAdmin->exec('GRANT INSERT ON sites_preview.reviews TO '.$rpRole);
        try {
            $rpInsert = $rpDb->prepare('INSERT INTO sites_preview.reviews(project_id,company_id,revision,sha256,reviewer,decision,note,created_at) VALUES(?::uuid,?::uuid,1,?,session_user,?,?,?::timestamptz)');
            $rpNote = 'Parecer sintético '.str_pad((string) $rpNumber, 2, '0', STR_PAD_LEFT)." — café 漢😀 <b data-pagination-probe=\"literal\">texto</b>\nLinha seguinte.";
            $rpInsert->execute([$rpProjects['a'], $rpCompanies['a'], $rpDigests[1], $rpNumber === 7 ? 'changes_requested' : 'approved_internal', $rpNote, $rpOriginal['tie_at']]);
            if ($rpInsert->rowCount() !== 1) throw new RuntimeException('One synthetic tie row required');
        } finally {
            $rpAdmin->exec('REVOKE INSERT ON sites_preview.reviews FROM '.$rpRole);
            $rpReviewers[$rpNumber]->assertSafeRuntime();
            if ($rpDb->query("SELECT has_table_privilege(current_user,'sites_preview.reviews','INSERT')")->fetchColumn() !== false) throw new RuntimeException('Temporary fixture capability remains');
        }
    }
    if ($rpAdmin->query("SELECT relacl::text FROM pg_class WHERE oid='sites_preview.reviews'::regclass")->fetchColumn() !== $rpAclBefore) throw new RuntimeException('Fixture grants were not restored exactly');
    $rpRevoke = $rpAdmin->prepare('UPDATE sites_preview.principals SET revoked_at=clock_timestamp() WHERE database_login=? AND revoked_at IS NULL');
    $rpRevoke->execute([$rpRoles[21]]);
    if ($rpRevoke->rowCount() !== 1) throw new RuntimeException('Synthetic historical reviewer revocation required');
    return ['projects' => $rpProjects, 'companies' => $rpCompanies, 'digests' => $rpDigests, 'roles' => $rpRoles,
        'tie_at' => $rpOriginal['tie_at'], 'api_reviews' => 120, 'synthetic_reviews' => 15];
};
