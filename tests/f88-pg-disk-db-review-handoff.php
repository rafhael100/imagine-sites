<?php

declare(strict_types=1);

/**
 * F1-88: exercise the complete disposable chain: structured draft -> fixed
 * private files -> independent readback -> reviewer decision -> PG18 ledger
 * -> restricted tenant readback -> non-authorizing review handoff.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('SITES_F79_PG_LAB') !== '1' ||
    getenv('SITES_F88_DISK_DB_LAB') !== '1') {
    fwrite(STDERR, "F1-88 only runs in an explicit disposable PostgreSQL CLI lab.\n");
    exit(1);
}
spl_autoload_register(static function(string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    if (!preg_match(
        '/\A(?:Application|Domain|Production|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',
        $relative
    )) {
        throw new RuntimeException('Unsafe synthetic class name');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Application\LabDiskAndDatabaseBoundReviewHandoff as Handoff;
use Imagine\Sites\Persistence\LabInternalReviewDecisionStore as Store;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;
use Imagine\Sites\Production\SyntheticInternalReviewDecision as Decision;
use Imagine\Sites\Production\SyntheticPrivateDraftWriter as Writer;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verifier;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;

$db = static function(): PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=sites_f79_lab',
        'postgres',
        'f79-lab-ephemeral-fixture-only',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
    );
};
$a = $db(); $a->exec('SET ROLE sites_f79_writer_a');
$b = $db(); $b->exec('SET ROLE sites_f79_writer_b');
$admin = $db();

$copy = [
    'schemaVersion'=>'sites.ai-copy.v1',
    'headline'=>'Soluções digitais para pequenos negócios',
    'description'=>'Demonstração inteiramente fictícia com serviços, informações de atendimento, benefícios e detalhes para uma prévia de revisão.',
    'callToAction'=>'Conheça os serviços',
    'benefits'=>['Conteúdo claro','Orientação útil','Organização'],
];

$build = static function(
    string $tenant, string $product, string $preset,
    string $site, string $workflow, string $release, string $decisionRef
) use ($copy): array {
    $draft = Assembly::build(
        $product, 'Marca Fictícia', $preset, $copy,
        $tenant, $site, $workflow, $release
    );
    $dossier = Dossier::fromDraft($draft);
    $pending = $dossier->record['requiredReviewerAcknowledgments'];
    $decision = Decision::decide(
        $draft, 'imagine_reviewer', 'reviewer-f88-lab', $decisionRef,
        'approve_internal', 2, $draft->artifact->digest,
        $draft->bundle->bundleDigest, $pending, null, $dossier->digest
    );
    $summary = $draft->reviewSummary();
    $summary['reviewDossierDigest'] = $dossier->digest;
    $bytes = [
        'index.html' => $draft->artifact->html,
        'internal-review-dossier.json' => $dossier->json,
        'internal-review-draft.json' => json_encode(
            $summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        )."\n",
        'release-manifest.json' => $draft->bundle->files['release-manifest.json'],
    ];
    return [$draft, $decision, $bytes];
};

$root = sys_get_temp_dir().'/sites-f88-'.bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) throw new RuntimeException('F88 scratch root not created');
$dirA = $root.'/sites-f1-72-onepage-modern-basic';
$dirB = $root.'/sites-f1-72-landing_page-warm-focused';
$checks = 0;
function f88Assert(bool $pass, string $label): void {
    global $checks;
    if (!$pass) throw new RuntimeException('FAIL F1-88 '.$label);
    ++$checks;
    echo "PASS F1-88 $label\n";
}
function f88Denied(callable $call): bool {
    try { $call(); } catch (DomainException|PDOException) { return true; }
    return false;
}

try {
    [$draftA, $decisionA, $bytesA] = $build(
        'tenant-f79-a', 'onepage', 'modern',
        'site-f88-a', 'workflow-f88-a', 'release-f88-a', 'decision-f88-a'
    );
    [$draftB, $decisionB, $bytesB] = $build(
        'tenant-f79-b', 'landing_page', 'warm',
        'site-f88-b', 'workflow-f88-b', 'release-f88-b', 'decision-f88-b'
    );

    $proofA = Writer::create($dirA, $bytesA);
    $proofB = Writer::create($dirB, $bytesB);
    f88Assert($proofA === Verifier::verifyDirectory($dirA) &&
        $proofA['workflowRef'] === 'workflow-f88-a' &&
        $proofB['workflowRef'] === 'workflow-f88-b',
        'both private draft packages are independently verified on disk');

    f88Assert(f88Denied(fn()=>Handoff::prepare($a, $dirA, $draftA, $decisionA)),
        'disk verification alone cannot imply a persisted decision');
    f88Assert(f88Denied(fn()=>Handoff::prepare($admin, $dirA, $draftA, $decisionA)),
        'lab administrator cannot substitute for restricted review tenant');

    Store::append($a, $decisionA);
    Store::append($b, $decisionB);
    $handoffA = Handoff::prepare($a, $dirA, $draftA, $decisionA);
    $handoffB = Handoff::prepare($b, $dirB, $draftB, $decisionB);
    f88Assert($handoffA['verifiedArtifactDigest'] === $proofA['artifactDigest'] &&
        $handoffA['verifiedBundleDigest'] === $proofA['bundleDigest'] &&
        $handoffA['verifiedDossierDigest'] === $proofA['dossierDigest'] &&
        $handoffA['verifiedDecisionDigest'] === $decisionA->reviewDigest,
        'the exact disk HTML, bundle, dossier and persisted PG record agree');
    f88Assert($handoffB['candidate']['tenantRef'] === 'tenant-f79-b' &&
        $handoffB['candidate']['workflowRef'] === 'workflow-f88-b' &&
        $handoffB['candidate']['releaseRef'] === 'release-f88-b',
        'second tenant has a separate persisted review candidate');
    f88Assert($handoffA['diskReadbackPerformed'] === true &&
        $handoffA['restrictedPgReadbackPerformed'] === true &&
        $handoffA['syntheticOnly'] === true &&
        $handoffA['externalPortalAuthorityRequired'] === true &&
        $handoffA['publishable'] === false &&
        $handoffA['officialClientAcceptance'] === false &&
        $handoffA['candidate']['publishable'] === false &&
        !array_key_exists('publishUrl', $handoffA),
        'two-source evidence never becomes customer acceptance or publication');

    f88Assert(f88Denied(fn()=>Handoff::prepare($b, $dirA, $draftA, $decisionA)) &&
        f88Denied(fn()=>Handoff::prepare($a, $dirB, $draftB, $decisionB)),
        'cross-tenant PG role does not validate the other tenant package');

    $htmlPath = $dirA.'/index.html';
    $htmlOriginal = (string)file_get_contents($htmlPath);
    file_put_contents($htmlPath, $htmlOriginal."\n");
    f88Assert(f88Denied(fn()=>Handoff::prepare($a, $dirA, $draftA, $decisionA)),
        'post-review HTML mutation is denied even with valid DB evidence');
    file_put_contents($htmlPath, $htmlOriginal);

    $dossierPath = $dirA.'/internal-review-dossier.json';
    $dossierOriginal = (string)file_get_contents($dossierPath);
    file_put_contents($dossierPath, $dossierOriginal."\n");
    f88Assert(f88Denied(fn()=>Handoff::prepare($a, $dirA, $draftA, $decisionA)),
        'dossier change is denied before persisted PG evidence is consulted');
    file_put_contents($dossierPath, $dossierOriginal);

    $manifestPath = $dirA.'/release-manifest.json';
    $manifestOriginal = (string)file_get_contents($manifestPath);
    file_put_contents($manifestPath, $manifestOriginal."\n");
    f88Assert(f88Denied(fn()=>Handoff::prepare($a, $dirA, $draftA, $decisionA)),
        'tampered release manifest is rejected by canonical verifier');
    file_put_contents($manifestPath, $manifestOriginal);

    f88Assert(f88Denied(fn()=>Handoff::prepare($a, $dirB, $draftA, $decisionA)),
        'cross-release draft files never match the same stored decision');

    [$otherWorkflow, $otherDecision] = $build(
        'tenant-f79-a', 'onepage', 'modern',
        'site-f88-a', 'workflow-f88-forged', 'release-f88-a', 'decision-f88-forged'
    );
    f88Assert(f88Denied(fn()=>Handoff::prepare(
        $a, $dirA, $otherWorkflow, $otherDecision
    )), 'workflowRef cannot be substituted while HTML and release remain equal');

    $rework = Decision::decide(
        $draftA, 'imagine_reviewer', 'reviewer-f88-lab',
        'decision-f88-rework', 'request_rework', 2,
        $draftA->artifact->digest, $draftA->bundle->bundleDigest,
        [], 'copy_revision', Dossier::fromDraft($draftA)->digest
    );
    f88Assert(f88Denied(fn()=>Handoff::prepare($a, $dirA, $draftA, $rework)),
        'rework is not an internal approval even if the draft was verified');

    putenv('SITES_F88_DISK_DB_LAB=0');
    f88Assert(f88Denied(fn()=>Handoff::prepare($a, $dirA, $draftA, $decisionA)),
        'lab handoff is disabled without its separate opt-in');
    putenv('SITES_F88_DISK_DB_LAB=1');

    f88Assert(
        Handoff::prepare($a, $dirA, $draftA, $decisionA)['publishable'] === false &&
        Handoff::prepare($b, $dirB, $draftB, $decisionB)['publishable'] === false,
        'reconstructed two-source proofs are deterministic and non-publishable');

    echo "\n$checks F1-88 disk+PostgreSQL integration assertions passed.\n";
} finally {
    // Never traverse arbitrary names or follow symlinks in cleanup.
    foreach ([$dirA, $dirB] as $dir) {
        foreach ([
            'index.html','internal-review-dossier.json',
            'internal-review-draft.json','release-manifest.json',
        ] as $name) {
            if (is_file($dir.'/'.$name) || is_link($dir.'/'.$name)) @unlink($dir.'/'.$name);
        }
        if (is_dir($dir) && !is_link($dir)) @rmdir($dir);
    }
    @rmdir($root);
}
echo "NO client identity, external Portal, public API, upload or deployment.\n";
