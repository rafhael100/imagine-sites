<?php

declare(strict_types=1);

use App\Preview\PreviewBriefInput;
use Illuminate\Validation\ValidationException;
use Imagine\Sites\Production\PrivatePreviewComparison;
use Imagine\Sites\Production\PrivatePreviewHistory;
use Imagine\Sites\Production\PreviewRevisionConflict;

// Run after all original counters. Keep every temporary in this closed scope:
// other fixture includes must never change the identity used by this fixture.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SITES_LOCAL_ROLE_CONTRACT') !== '1' ||
    !isset($admin, $connections, $workspaces, $companies, $ok, $count)) {
    throw new RuntimeException('Revision ceiling fixture unavailable');
}
(static function (PDO $rcAdmin, array $rcConnections, array $rcWorkspaces, array $rcCompanies, callable $rcOk): void {
    $rcChecks = 0;
    $rcCheck = static function (bool $rcCondition, string $rcLabel) use ($rcOk, &$rcChecks): void {
        $rcOk($rcCondition, 'revision ceiling: '.$rcLabel);
        ++$rcChecks;
    };
    $rcState = static function () use ($rcAdmin): array {
        $rcResult = [];
        foreach (['principals', 'projects', 'versions', 'reviews', 'audit'] as $rcTable) {
            $rcResult[$rcTable] = json_decode($rcAdmin->query("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]'::jsonb)::text FROM sites_preview.$rcTable r")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        }
        return $rcResult;
    };
    $rcRowsAdded = static function (array $rcBefore, array $rcAfter): ?array {
        foreach ($rcBefore as $rcRow) {
            $rcIndex = array_search($rcRow, $rcAfter, true);
            if ($rcIndex === false) return null;
            unset($rcAfter[$rcIndex]);
        }
        return array_values($rcAfter);
    };
    $rcCanonicalBrief = static function (array $rcBrief): array { ksort($rcBrief); return $rcBrief; };
    $rcOnlyAppend = static function (array $rcBefore, array $rcAfter, string $rcTable, string $rcProject, int $rcRevision, string $rcActor) use ($rcRowsAdded): ?array {
        foreach (['principals', 'projects', 'versions', 'reviews'] as $rcUnchanged) {
            if ($rcUnchanged !== $rcTable && $rcBefore[$rcUnchanged] !== $rcAfter[$rcUnchanged]) return null;
        }
        $rcRows = $rcRowsAdded($rcBefore[$rcTable], $rcAfter[$rcTable]);
        $rcAudit = $rcRowsAdded($rcBefore['audit'], $rcAfter['audit']);
        if ($rcRows === null || $rcAudit === null || count($rcRows) !== 1 || count($rcAudit) !== 1 ||
            $rcRows[0]['project_id'] !== $rcProject || $rcRows[0]['revision'] !== $rcRevision ||
            $rcRows[0][$rcTable === 'versions' ? 'author' : 'reviewer'] !== $rcActor ||
            $rcAudit[0]['project_id'] !== $rcProject || $rcAudit[0]['company_id'] !== $rcRows[0]['company_id'] ||
            $rcAudit[0]['actor'] !== $rcActor || $rcAudit[0]['event'] !== $rcTable.':INSERT') return null;
        return $rcRows[0];
    };
    $rcPayload = static fn (string $rcProduct, string $rcHeadline, ?int $rcExpected = null): array => [
        'product' => $rcProduct, 'brand' => 'Limite sintético '.$rcProduct,
        'headline' => $rcHeadline, 'description' => 'Texto fictício com https://example.invalid/ e onboarding=literal.',
        'call_to_action' => 'Consultar', 'benefits' => "Histórico preservado\nTexto literal <strong>sem formato</strong>",
    ] + ($rcExpected === null ? [] : ['expected_revision' => (string) $rcExpected]);
    $rcProjects = [
        'onepage' => ['id' => '93000000-0000-4000-8000-000000000001', 'head' => 10000],
        'landing_page' => ['id' => '93000000-0000-4000-8000-000000000002', 'head' => 2147483646],
    ];
    $rcAuthor = $rcConnections['a']['author']->query('SELECT session_user::text')->fetchColumn();
    $rcReviewer = $rcConnections['a']['reviewer']->query('SELECT session_user::text')->fetchColumn();
    $rcReader = $rcConnections['a']['reader']->query('SELECT session_user::text')->fetchColumn();
    foreach ($rcProjects as $rcProduct => $rcCase) {
        $rcBrief = PreviewBriefInput::parse($rcPayload($rcProduct, 'Versão inicial do limite'));
        $rcFirst = $rcWorkspaces['a']['author']->createDemonstration($rcCase['id'], $rcCompanies['a'], $rcBrief);
        // A sparse synthetic head needs one row, not millions. The native author
        // retains identity/RLS/audit triggers and copies an actual rendered row.
        $rcSeed = $rcConnections['a']['author']->prepare('INSERT INTO sites_preview.versions(project_id,company_id,revision,brief,html,sha256) SELECT project_id,company_id,?,brief,html,sha256 FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid AND revision=1');
        $rcBefore = $rcState();
        $rcSeed->execute([$rcCase['head'], $rcCase['id'], $rcCompanies['a']]);
        $rcRow = $rcOnlyAppend($rcBefore, $rcState(), 'versions', $rcCase['id'], $rcCase['head'], $rcAuthor);
        $rcCheck($rcSeed->rowCount() === 1 && $rcRow !== null && $rcCanonicalBrief($rcRow['brief']) === $rcCanonicalBrief(get_object_vars($rcBrief)) &&
            $rcRow['sha256'] === $rcFirst['sha256'] && hash('sha256', $rcRow['html']) === $rcFirst['sha256'],
            $rcProduct.' sparse head preserves real bytes and appends one authorized version and audit row');
    }
    $rcAppend = static function (string $rcProduct, int $rcExpected, string $rcHeadline) use ($rcPayload, $rcProjects, $rcWorkspaces, $rcCompanies, $rcState, $rcOnlyAppend, $rcAuthor, $rcCanonicalBrief, $rcCheck): array {
        $rcBrief = PreviewBriefInput::parse($rcPayload($rcProduct, $rcHeadline, $rcExpected), $rcProduct);
        $rcBefore = $rcState();
        $rcResult = $rcWorkspaces['a']['author']->revise($rcProjects[$rcProduct]['id'], $rcCompanies['a'], $rcExpected, $rcBrief);
        $rcRow = $rcOnlyAppend($rcBefore, $rcState(), 'versions', $rcProjects[$rcProduct]['id'], $rcExpected + 1, $rcAuthor);
        $rcCheck($rcRow !== null && $rcCanonicalBrief($rcRow['brief']) === $rcCanonicalBrief(get_object_vars($rcBrief)) && $rcRow['sha256'] === $rcResult['sha256'] &&
            hash('sha256', $rcRow['html']) === $rcResult['sha256'] && $rcResult['revision'] === $rcExpected + 1 &&
            $rcResult['publishable'] === false && $rcResult['client_accepted'] === false,
            'validated CAS'.$rcExpected.' appends exactly one immutable successor and one audit row');
        return $rcResult;
    };
    $rcHighOne = $rcAppend('onepage', 10000, 'Versão real 10001');
    $rcHighTwo = $rcAppend('onepage', 10001, 'Versão real 10002');
    $rcLast = $rcAppend('landing_page', 2147483646, 'Última versão real 2147483647');
    $rcHistory = new PrivatePreviewHistory($rcConnections['a']['reader']);
    $rcComparison = new PrivatePreviewComparison($rcConnections['a']['reader']);
    $rcBefore = $rcState();
    $rcPage = $rcHistory->page($rcCompanies['a'], $rcProjects['onepage']['id']);
    $rcCheck(array_column($rcPage['versions'], 'revision') === [10002, 10001, 10000, 1] &&
        $rcPage['current_revision'] === 10002 && $rcPage['current_sha256'] === $rcHighTwo['sha256'] &&
        $rcPage['brief']['headline'] === 'Versão real 10002', 'history keeps the real successors beyond the former10000 ceiling');
    $rcTerminal = $rcHistory->page($rcCompanies['a'], $rcProjects['landing_page']['id'], 2147483647);
    $rcCheck(array_column($rcTerminal['versions'], 'revision') === [2147483646, 1] &&
        $rcTerminal['current_revision'] === 2147483647 && $rcTerminal['current_sha256'] === $rcLast['sha256'] &&
        $rcTerminal['brief']['headline'] === 'Última versão real 2147483647',
        'an older history window preserves the terminal head and its saved briefing');
    $rcCompared = $rcComparison->compare($rcCompanies['a'], $rcProjects['landing_page']['id'], 2147483646, 2147483647);
    $rcCheck($rcCompared['from']['revision'] === 2147483646 && $rcCompared['to']['revision'] === 2147483647 &&
        $rcCompared['to']['sha256'] === $rcLast['sha256'] && $rcCompared['changed_fields'] === ['headline'],
        'terminal comparison reads exact integers, saved text and digest');
    $rcStored = $rcWorkspaces['a']['reader']->view($rcProjects['landing_page']['id'], $rcCompanies['a'], 2147483647);
    $rcCheck(hash('sha256', $rcStored['html']) === $rcLast['sha256'] && $rcState() === $rcBefore,
        'terminal HTML, history and comparison leave every persisted table unchanged');

    $rcBefore = $rcState();
    $rcWorkspaces['a']['reviewer']->review($rcProjects['landing_page']['id'], $rcCompanies['a'], 2147483647, $rcLast['sha256'], 'approved_internal', 'Parecer sintético no limite.');
    $rcReview = $rcOnlyAppend($rcBefore, $rcState(), 'reviews', $rcProjects['landing_page']['id'], 2147483647, $rcReviewer);
    $rcCheck($rcReview !== null && $rcReview['sha256'] === $rcLast['sha256'] && $rcReview['decision'] === 'approved_internal' &&
        $rcReview['note'] === 'Parecer sintético no limite.', 'independent terminal review appends only its exact review and audit row');
    $rcBefore = $rcState();
    $rcReviewed = $rcHistory->page($rcCompanies['a'], $rcProjects['landing_page']['id']);
    $rcCheck(count($rcReviewed['current_reviews']) === 1 && $rcReviewed['current_reviews'][0]['revision'] === 2147483647 &&
        $rcReviewed['current_reviews'][0]['sha256'] === $rcLast['sha256'] && $rcState() === $rcBefore,
        'the terminal editorial decision remains visible without changing versions');

    $rcBefore = $rcState();
    $rcDenied = false;
    try {
        $rcBrief = PreviewBriefInput::parse($rcPayload('landing_page', 'Tentativa acima do limite', 2147483647), 'landing_page');
        $rcWorkspaces['a']['author']->revise($rcProjects['landing_page']['id'], $rcCompanies['a'], 2147483647, $rcBrief);
    } catch (ValidationException $rcError) {
        $rcDenied = array_keys($rcError->errors()) === ['expected_revision'];
    }
    $rcCheck($rcDenied && $rcState() === $rcBefore, 'terminal CAS is a validation refusal before append with no persisted mutation');
    $rcDenied = false;
    try {
        $rcBrief = PreviewBriefInput::parse($rcPayload('onepage', 'Tentativa obsoleta', 10001), 'onepage');
        $rcWorkspaces['a']['author']->revise($rcProjects['onepage']['id'], $rcCompanies['a'], 10001, $rcBrief);
    } catch (PreviewRevisionConflict) { $rcDenied = true; }
    $rcCheck($rcDenied && $rcState() === $rcBefore, 'accepted high integers still enforce stale-CAS refusal without an append');
    $rcDenied = false;
    try {
        $rcWorkspaces['b']['author']->revise($rcProjects['onepage']['id'], $rcCompanies['a'], 10002,
            PreviewBriefInput::parse($rcPayload('onepage', 'Tentativa de outra empresa', 10002), 'onepage'));
    } catch (RuntimeException $rcError) { $rcDenied = $rcError->getMessage() === 'Preview product/scope denied'; }
    $rcCheck($rcDenied && $rcState() === $rcBefore, 'a currently appendable high head does not authorize a different company');
    $rcDenied = false;
    try {
        (new PrivatePreviewComparison($rcConnections['b']['reader']))->compare($rcCompanies['b'], $rcProjects['landing_page']['id'], 2147483646, 2147483647);
    } catch (OutOfBoundsException $rcError) { $rcDenied = $rcError->getMessage() === 'Private preview comparison unavailable'; }
    $rcCheck($rcDenied && $rcState() === $rcBefore, 'a proven readable terminal comparison remains isolated from the other company');

    // These are reversible revocation probes on synthetic preview principals,
    // restored in finally. No operational principal or grant is changed.
    foreach (['author' => $rcAuthor, 'reader' => $rcReader] as $rcProfile => $rcLogin) {
        $rcChange = $rcAdmin->prepare('UPDATE sites_preview.principals SET revoked_at=clock_timestamp() WHERE database_login=? AND revoked_at IS NULL');
        $rcChange->execute([$rcLogin]);
        if ($rcChange->rowCount() !== 1) throw new RuntimeException('Expected active synthetic ceiling principal');
        try {
            $rcRevokedState = $rcState();
            $rcDenied = false;
            try {
                if ($rcProfile === 'author') {
                    $rcWorkspaces['a']['author']->revise($rcProjects['onepage']['id'], $rcCompanies['a'], 10002,
                        PreviewBriefInput::parse($rcPayload('onepage', 'Tentativa após revogação', 10002), 'onepage'));
                } else {
                    $rcHistory->page($rcCompanies['a'], $rcProjects['landing_page']['id']);
                }
            } catch (RuntimeException) { $rcDenied = true; }
            $rcCheck($rcDenied && $rcState() === $rcRevokedState, $rcProfile.' revocation is rechecked on the existing high-revision connection');
        } finally {
            $rcRestore = $rcAdmin->prepare('UPDATE sites_preview.principals SET revoked_at=NULL WHERE database_login=?');
            $rcRestore->execute([$rcLogin]);
        }
    }
    $rcCheck($rcState() === $rcBefore && $rcHistory->page($rcCompanies['a'], $rcProjects['landing_page']['id'])['current_revision'] === 2147483647,
        'revocation probes restore only their synthetic binding and preserve all terminal history');
    echo 'REVISION CEILING SQL PASS: '.$rcChecks." new assertions; 386 existing assertions retained. Sparse synthetic heads, real appends and terminal read/review only.\n";
})($admin, $connections, $workspaces, $companies, $ok);
