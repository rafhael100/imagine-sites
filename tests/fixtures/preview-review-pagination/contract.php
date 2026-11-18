<?php

declare(strict_types=1);

use Imagine\Sites\Production\PrivatePreviewHistory;
use Imagine\Sites\Production\PreviewReviewQuery;
use Imagine\Sites\Production\SiteBrief;

if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('SITES_LOCAL_ROLE_CONTRACT') !== '1' ||
    !isset($admin, $connection, $connections, $workspaces, $companies, $ok)) throw new RuntimeException('Verified role fixture required');
(static function (PDO $rpAdmin, $rpConnection, array $rpConnections, array $rpWorkspaces, array $rpCompanies, callable $rpOk): void {
    $rpChecks = 0;
    $rpCheck = static function (bool $rpCondition, string $rpLabel) use ($rpOk, &$rpChecks): void {
        $rpOk($rpCondition, 'review pagination: '.$rpLabel); ++$rpChecks;
    };
    $rpPassword = $rpConnection->getConfig('password');
    if (!is_string($rpPassword) || $rpConnection->getPdo() !== $rpAdmin ||
        $rpConnection->getConfig('username') !== $rpAdmin->query('SELECT session_user::text')->fetchColumn()) {
        throw new RuntimeException('Original verified administrator required');
    }
    $rpConnect = static fn (string $rpRole): PDO => new PDO('pgsql:host=127.0.0.1;port=55448;dbname=postgres', $rpRole, $rpPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $rpSeed = require __DIR__.'/seed.php';
    $rpFixture = $rpSeed($rpAdmin, ['a' => $rpConnections['a']['author'], 'b' => $rpConnections['b']['author']], $rpConnect, $rpPassword, 'lab511_rp_');
    $rpProject = $rpFixture['projects']['a']; $rpCompany = $rpCompanies['a']; $rpDigest = $rpFixture['digests'][1];
    $rpState = static function () use ($rpAdmin): array {
        $rpResult = [];
        foreach (['principals', 'projects', 'versions', 'reviews', 'audit'] as $rpTable) {
            $rpResult[$rpTable] = json_decode($rpAdmin->query("SELECT coalesce(jsonb_agg(to_jsonb(r) ORDER BY to_jsonb(r)::text),'[]'::jsonb)::text FROM sites_preview.$rpTable r")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        }
        return $rpResult;
    };
    $rpAdded = static function (array $rpBefore, array $rpAfter): ?array {
        foreach ($rpBefore as $rpRow) {
            $rpIndex = array_search($rpRow, $rpAfter, true);
            if ($rpIndex === false) return null;
            unset($rpAfter[$rpIndex]);
        }
        return array_values($rpAfter);
    };
    $rpOnlyAppend = static function (array $rpBefore, array $rpAfter, string $rpTable, int $rpRevision, string $rpActor) use ($rpAdded, $rpProject, $rpCompany): ?array {
        foreach (['principals', 'projects', 'versions', 'reviews'] as $rpOther) {
            if ($rpOther !== $rpTable && $rpBefore[$rpOther] !== $rpAfter[$rpOther]) return null;
        }
        $rpRows = $rpAdded($rpBefore[$rpTable], $rpAfter[$rpTable]); $rpAudit = $rpAdded($rpBefore['audit'], $rpAfter['audit']);
        if (!is_array($rpRows) || !is_array($rpAudit) || count($rpRows) !== 1 || count($rpAudit) !== 1 ||
            $rpRows[0]['project_id'] !== $rpProject || $rpRows[0]['company_id'] !== $rpCompany || $rpRows[0]['revision'] !== $rpRevision ||
            $rpRows[0][$rpTable === 'reviews' ? 'reviewer' : 'author'] !== $rpActor ||
            $rpAudit[0]['project_id'] !== $rpProject || $rpAudit[0]['company_id'] !== $rpCompany ||
            $rpAudit[0]['actor'] !== $rpActor || $rpAudit[0]['event'] !== $rpTable.':INSERT') return null;
        return $rpRows[0];
    };
    $rpNote = static fn (int $rpNumber): string => 'Parecer sintético '.str_pad((string) $rpNumber, 2, '0', STR_PAD_LEFT)." — café 漢😀 <b data-pagination-probe=\"literal\">texto</b>\nLinha seguinte.";
    $rpReader = new PrivatePreviewHistory($rpConnections['a']['reader']);
    $rpBReader = new PrivatePreviewHistory($rpConnections['b']['reader']);
    $rpFacts = $rpAdmin->prepare("SELECT count(*) AS reviews,count(DISTINCT revision) AS versions,
        count(*) FILTER(WHERE revision=1) AS first_reviews,count(*) FILTER(WHERE created_at=?::timestamptz) AS tied
        FROM sites_preview.reviews WHERE project_id=?::uuid AND company_id=?::uuid");
    $rpFacts->execute([$rpFixture['tie_at'], $rpProject, $rpCompany]); $rpFact = $rpFacts->fetch(PDO::FETCH_ASSOC);
    $rpCheck((int) $rpFact['reviews'] === 135 && (int) $rpFact['versions'] === 20 && (int) $rpFact['first_reviews'] === 21 &&
        (int) $rpFact['tied'] === 15 && $rpFixture['api_reviews'] === 120 && $rpFixture['synthetic_reviews'] === 15 &&
        (int) $rpAdmin->query("SELECT count(*) FROM pg_roles WHERE starts_with(rolname::text,'lab511_rp_') AND has_table_privilege(oid,'sites_preview.reviews','INSERT')")->fetchColumn() === 0,
        'twenty native six-review groups and fifteen explicit ordering rows exist with every temporary INSERT capability removed');
    $rpBefore = $rpState();
    $rpLegacy = $rpReader->page($rpCompany, $rpProject);
    $rpCheck(count($rpLegacy['versions']) === 20 && count($rpLegacy['reviews']) === 100 && $rpLegacy['reviews_truncated'] === true &&
        !in_array(1, array_column($rpLegacy['reviews'], 'revision'), true),
        'the existing bounded summary genuinely omits all version1 opinions beyond its100-review window');
    $rpFirst = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1));
    $rpSafeRows = true;
    foreach ($rpFirst['reviews'] as $rpRow) $rpSafeRows = $rpSafeRows && array_keys($rpRow) === ['revision', 'sha256', 'decision', 'note', 'created_at'] &&
        $rpRow['revision'] === 1 && $rpRow['sha256'] === $rpDigest;
    $rpCheck(array_keys($rpFirst) === ['revision', 'sha256', 'reviews', 'next_cursor'] && $rpFirst['revision'] === 1 &&
        $rpFirst['sha256'] === $rpDigest && count($rpFirst['reviews']) === 20 && $rpSafeRows &&
        is_string($rpFirst['next_cursor']) && preg_match('/\A[a-f0-9]{64}\z/D', $rpFirst['next_cursor']) === 1,
        'the exact omitted version now exposes twenty opinions and one opaque continuation without reviewer names or per-row tokens');
    $rpFirstNative = array_slice($rpFirst['reviews'], 0, 6); $rpNativeNotes = array_column($rpFirstNative, 'note');
    $rpExpectedNativeNotes = [null]; for ($rpNumber = 2; $rpNumber <= 6; ++$rpNumber) $rpExpectedNativeNotes[] = 'Parecer API 0'.$rpNumber.' da versão 1.';
    sort($rpNativeNotes); sort($rpExpectedNativeNotes);
    $rpCheck($rpNativeNotes === $rpExpectedNativeNotes && array_unique(array_column($rpFirstNative, 'decision')) === ['approved_internal'] &&
        array_column(array_slice($rpFirst['reviews'], 6), 'note') === array_map($rpNote, range(7, 20)) &&
        count(array_unique(array_column(array_slice($rpFirst['reviews'], 6), 'created_at'))) === 1 && $rpFirst['reviews'][6]['decision'] === 'changes_requested',
        'NULL and exact multiline Unicode notes survive while tied reviewers07..20 are ordered by the stable private C key');
    $rpSecond = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, $rpFirst['next_cursor']));
    $rpRevoked = $rpAdmin->prepare('SELECT revoked_at IS NOT NULL FROM sites_preview.principals WHERE database_login=?'); $rpRevoked->execute([$rpFixture['roles'][21]]);
    $rpCheck(count($rpSecond['reviews']) === 1 && $rpSecond['reviews'][0]['note'] === $rpNote(21) && $rpSecond['next_cursor'] === null &&
        $rpSecond['reviews'][0]['created_at'] === $rpFirst['reviews'][19]['created_at'] && $rpRevoked->fetchColumn() === true,
        'the tied21st opinion is reached once even though its original reviewer has been revoked');
    $rpCheck(count(array_unique(array_column([...$rpFirst['reviews'], ...$rpSecond['reviews']], 'note'), SORT_REGULAR)) === 21 &&
        $rpState() === $rpBefore, 'two readonly pages recover every omitted opinion without duplicates or changes to any persisted table');

    $rpNewNote = 'Parecer novo depois da primeira página.';
    $rpBefore = $rpState();
    $rpWorkspaces['a']['reviewer']->review($rpProject, $rpCompany, 1, $rpDigest, 'approved_internal', $rpNewNote);
    $rpReviewerLogin = $rpConnections['a']['reviewer']->query('SELECT session_user::text')->fetchColumn();
    $rpNewRow = $rpOnlyAppend($rpBefore, $rpState(), 'reviews', 1, $rpReviewerLogin);
    $rpCheck($rpNewRow !== null && $rpNewRow['sha256'] === $rpDigest && $rpNewRow['note'] === $rpNewNote,
        'one newer opinion is actually appended through the independent review API, with only its own audit row');
    $rpBefore = $rpState();
    $rpNewVersion = $rpWorkspaces['a']['author']->revise($rpProject, $rpCompany, 20, new SiteBrief('onepage', 'Pareceres CI',
        'Pareceres CI versão 21', 'Conteúdo fictício mais recente sem parecer herdado.', 'Consultar', ['Histórico independente da página']));
    $rpAuthorLogin = $rpConnections['a']['author']->query('SELECT session_user::text')->fetchColumn();
    $rpVersionRow = $rpOnlyAppend($rpBefore, $rpState(), 'versions', 21, $rpAuthorLogin);
    $rpCheck($rpVersionRow !== null && $rpVersionRow['sha256'] === $rpNewVersion['sha256'] && hash('sha256', $rpVersionRow['html']) === $rpNewVersion['sha256'],
        'a real newer project head appends version21 and its audit without rewriting any opinion or older version');
    $rpBefore = $rpState();
    $rpContinued = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, $rpFirst['next_cursor']));
    $rpCurrent = $rpReader->page($rpCompany, $rpProject, 21);
    $rpCheck($rpContinued === $rpSecond && $rpCurrent['current_revision'] === 21 && $rpCurrent['current_sha256'] === $rpNewVersion['sha256'] &&
        $rpCurrent['current_reviews'] === [] && array_column($rpCurrent['versions'], 'revision') === range(20, 1),
        'the original keyset continuation is unchanged after a newer opinion and head, while history still edits the actual head21');
    $rpFresh = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1));
    $rpFreshTail = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, $rpFresh['next_cursor']));
    $rpCheck($rpFresh['reviews'][0]['note'] === $rpNewNote && count($rpFresh['reviews']) === 20 && $rpFresh['next_cursor'] !== $rpFirst['next_cursor'] &&
        array_column($rpFreshTail['reviews'], 'note') === [$rpNote(20), $rpNote(21)] && $rpFreshTail['next_cursor'] === null &&
        count(array_unique(array_column([...$rpFresh['reviews'], ...$rpFreshTail['reviews']], 'note'), SORT_REGULAR)) === 22,
        'starting again includes the new opinion and still visits all22 opinions exactly once');
    $rpTimezone = $rpConnections['a']['reader']->query('SHOW TimeZone')->fetchColumn();
    $rpConnections['a']['reader']->exec("SET TimeZone='America/Sao_Paulo'");
    try {
        $rpZonedFirst = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1));
        $rpZonedTail = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, $rpFirst['next_cursor']));
        $rpCheck($rpZonedFirst['next_cursor'] === $rpFresh['next_cursor'] && array_column($rpZonedTail['reviews'], 'note') === [$rpNote(21)] &&
            (new DateTimeImmutable($rpZonedTail['reviews'][0]['created_at']))->format('U.u') === (new DateTimeImmutable($rpSecond['reviews'][0]['created_at']))->format('U.u'),
            'changing the native session timezone preserves cursor identity and exact stored instants');
    } finally { $rpConnections['a']['reader']->exec('SET TimeZone='.$rpConnections['a']['reader']->quote($rpTimezone)); }

    // No normal pager emits a cursor after its last immutable row. Construct
    // only that boundary token from the documented PG cursor domain to test a
    // valid empty continuation; all other tokens come from the actual reader.
    $rpLastAnchor = $rpAdmin->prepare(<<<'SQL'
SELECT pg_catalog.encode(pg_catalog.sha256(pg_catalog.convert_to(pg_catalog.jsonb_build_array(
 'SITES_PREVIEW_REVIEW_CURSOR_V1',v.company_id::text,v.project_id::text,v.revision,v.sha256,
 pg_catalog.to_char(r.created_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),r.reviewer::text)::text,'UTF8')),'hex')
FROM sites_preview.versions v JOIN sites_preview.reviews r ON r.project_id=v.project_id AND r.company_id=v.company_id
 AND r.revision=v.revision AND r.sha256=v.sha256
WHERE v.project_id=?::uuid AND v.company_id=?::uuid AND v.revision=1 AND r.reviewer=?
SQL);
    $rpLastAnchor->execute([$rpProject, $rpCompany, $rpFixture['roles'][21]]); $rpLastCursor = $rpLastAnchor->fetchColumn();
    $rpExhausted = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, $rpLastCursor));
    $rpCheck($rpExhausted === ['revision' => 1, 'sha256' => $rpDigest, 'reviews' => [], 'next_cursor' => null],
        'a valid final anchor produces an empty continuation rather than an absent-version error');
    $rpEmpty = $rpReader->reviewsForVersion($rpCompany, $rpFixture['projects']['empty'], new PreviewReviewQuery(1));
    $rpEmptyDigest = $rpWorkspaces['a']['reader']->view($rpFixture['projects']['empty'], $rpCompany, 1)['sha256'];
    $rpCheck($rpEmpty === ['revision' => 1, 'sha256' => $rpEmptyDigest, 'reviews' => [], 'next_cursor' => null],
        'a different own version without any opinion remains a valid initial empty page');
    $rpVersionTwo = $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(2));
    $rpCheck($rpVersionTwo['revision'] === 2 && $rpVersionTwo['sha256'] === $rpFixture['digests'][2] && count($rpVersionTwo['reviews']) === 6 &&
        $rpVersionTwo['next_cursor'] === null, 'the other version used for the scope negative is independently readable');
    $rpRefused = static function (callable $rpOperation, string $rpClass, string $rpMessage, string $rpLabel) use ($rpState, $rpCheck): void {
        $rpBefore = $rpState(); $rpDenied = false;
        try { $rpOperation(); } catch (Throwable $rpError) { $rpDenied = get_class($rpError) === $rpClass && $rpError->getMessage() === $rpMessage; }
        $rpCheck($rpDenied && $rpState() === $rpBefore, $rpLabel.' is refused without changing any persisted row');
    };
    foreach ([[$rpProject, 2, $rpFirst['next_cursor'], 'cursor from another real version'],
        [$rpFixture['projects']['empty'], 1, $rpFirst['next_cursor'], 'cursor from another own project'],
        [$rpProject, 1, str_repeat('0', 64), 'well-formed cursor with no scoped anchor'],
        [$rpProject, 9999, null, 'well-formed revision that does not exist']] as [$rpTarget, $rpRevision, $rpCursor, $rpLabel]) {
        $rpRefused(static fn () => $rpReader->reviewsForVersion($rpCompany, $rpTarget, new PreviewReviewQuery($rpRevision, $rpCursor)),
            OutOfBoundsException::class, 'Private preview reviews unavailable', $rpLabel);
    }
    $rpBPage = $rpBReader->reviewsForVersion($rpCompanies['b'], $rpFixture['projects']['b'], new PreviewReviewQuery(1));
    $rpCheck($rpBPage['revision'] === 1 && $rpBPage['reviews'] === [] && $rpBPage['next_cursor'] === null,
        'company B successfully reads its own existing empty version before the company-isolation negatives');
    $rpRefused(static fn () => $rpReader->reviewsForVersion($rpCompany, $rpFixture['projects']['b'], new PreviewReviewQuery(1)),
        OutOfBoundsException::class, 'Private preview reviews unavailable', 'foreign existing project');
    $rpRefused(static fn () => $rpReader->reviewsForVersion($rpCompanies['b'], $rpFixture['projects']['b'], new PreviewReviewQuery(1)),
        RuntimeException::class, 'Private preview history unavailable', 'caller-supplied company cannot borrow a real foreign binding');
    $rpRefused(static fn () => $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, str_repeat('A', 64))),
        InvalidArgumentException::class, 'Invalid private preview review navigation', 'noncanonical uppercase cursor');
    $rpCheck($rpState() === $rpBefore, 'all reads, timezone changes and scope refusals preserve the full post-append state');

    $rpReaderLogin = $rpConnections['a']['reader']->query('SELECT session_user::text')->fetchColumn();
    $rpRevoke = $rpAdmin->prepare('UPDATE sites_preview.principals SET revoked_at=clock_timestamp() WHERE database_login=? AND revoked_at IS NULL');
    $rpRevoke->execute([$rpReaderLogin]);
    if ($rpRevoke->rowCount() !== 1) throw new RuntimeException('Active synthetic pagination reader required');
    try {
        $rpRefused(static fn () => $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, $rpFirst['next_cursor'])),
            RuntimeException::class, 'Private preview history unavailable', 'revocation on the same reader and previously successful cursor');
    } finally {
        $rpRestore = $rpAdmin->prepare('UPDATE sites_preview.principals SET revoked_at=NULL WHERE database_login=?'); $rpRestore->execute([$rpReaderLogin]);
    }
    $rpCheck($rpState() === $rpBefore && $rpReader->reviewsForVersion($rpCompany, $rpProject, new PreviewReviewQuery(1, $rpFirst['next_cursor'])) === $rpSecond,
        'restoring only the synthetic reader retains the exact continuation and every historical opinion');
    echo 'REVIEW PAGINATION SQL PASS: '.$rpChecks." new assertions; 404 existing assertions retained. 121 API reviews and 15 explicit timestamp fixtures; no login exposure.\n";
})($admin, $connection, $connections, $workspaces, $companies, $ok);
