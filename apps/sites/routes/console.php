<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;

/**
 * F1-07 only: generates a synthetic local file in storage/app/private.
 * Refuses outside APP_ENV=testing and an explicit laboratory flag.
 * No customer content, URLs, publisher, database, HTTP route or external API.
 */
Artisan::command('sites:preview-fixture {product=onepage}', function (): int {
    if (!app()->environment('testing') || getenv('SITES_SYNTHETIC_PREVIEW') !== '1') {
        $this->error('F1-07 private preview requires testing environment and explicit synthetic flag.');
        return Command::FAILURE;
    }

    $product = (string) $this->argument('product');
    if (!in_array($product, ['onepage', 'landing_page'], true)) {
        $this->error('Only fictional onepage or landing_page preview is allowed.');
        return Command::FAILURE;
    }

    $source = base_path('../../fixtures/sites/f1-06-onepage.synthetic.json');
    if (!is_file($source)) {
        $this->error('The synthetic brief fixture is not available.');
        return Command::FAILURE;
    }
    $data = json_decode((string) file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
    $data['productCode'] = $product;
    $artifact = (new StaticSiteRenderer())->render(new SiteBrief(...$data));

    $directory = storage_path('app/private');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        $this->error('Cannot create private scratch directory.');
        return Command::FAILURE;
    }
    $filename = $directory.'/sites-f1-07-'.$product.'.html';
    if (file_put_contents($filename, $artifact->html, LOCK_EX) !== $artifact->sizeBytes) {
        $this->error('Could not write exact artifact bytes.');
        return Command::FAILURE;
    }

    $this->line('Synthetic preview generated in private runner scratch space.');
    $this->line('SHA-256: '.$artifact->digest);
    $this->line('Bytes: '.$artifact->sizeBytes);
    return Command::SUCCESS;
})->purpose('Render a fictional private HTML preview in an isolated test runner only');


/**
 * F1-24: write only synthetic, non-publishable packages to a private runner
 * scratch path. Never targets Cloudflare, public web storage or customer data.
 */
Artisan::command('sites:package-fixture {product=onepage}', function (): int {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_BUNDLE') !== '1') {
        $this->error('Synthetic private package is disabled outside CLI testing with flag.');
        return Command::FAILURE;
    }
    $product=(string)$this->argument('product');
    if (!in_array($product,['onepage','landing_page'],true)) {
        $this->error('Only onepage or landing_page fictional package is allowed.');
        return Command::FAILURE;
    }
    $source=base_path('../../fixtures/sites/f1-06-onepage.synthetic.json');
    if (!is_file($source)) {
        $this->error('Synthetic source fixture is absent.');
        return Command::FAILURE;
    }
    $data=json_decode((string)file_get_contents($source),true,512,JSON_THROW_ON_ERROR);
    $data['productCode']=$product;
    $artifact=(new StaticSiteRenderer())->render(new SiteBrief(...$data));
    $release=new \Imagine\Sites\Domain\ReleaseManifest(
        'tenant-fixture','site-fixture','release-bundle-'.$product,$artifact->digest
    );
    $bundle=new \Imagine\Sites\Production\SyntheticReleaseBundle($release,$artifact);
    if (!\Imagine\Sites\Production\SyntheticReleaseBundle::verifyFiles($bundle->files,$release)) {
        $this->error('Built package did not pass integrity policy.');
        return Command::FAILURE;
    }

    $private=storage_path('app/private');
    $directory=$private.'/sites-f1-24-'.$product;
    if (is_link($private) || is_link($directory) ||
        (file_exists($directory) && !is_dir($directory))) {
        $this->error('Symlinked or unexpected private scratch path is forbidden.');
        return Command::FAILURE;
    }
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) {
        $this->error('Could not create private scratch directory.');
        return Command::FAILURE;
    }
    @chmod($directory,0700);
    foreach ($bundle->files as $name=>$bytes) {
        $target=$directory.'/'.$name;
        if (is_link($target)) {
            $this->error('Symbolic links are forbidden inside bundle output.');
            return Command::FAILURE;
        }
        if (file_put_contents($target,$bytes,LOCK_EX)!==strlen($bytes)) {
            $this->error('Cannot write bundle bytes.');
            return Command::FAILURE;
        }
        @chmod($target,0600);
    }
    $this->line('PRIVATE SYNTHETIC TEST PACKAGE ONLY — NOT PUBLISHABLE.');
    $this->line('Package SHA-256: '.$bundle->bundleDigest);
    $this->line('Artifact SHA-256: '.$artifact->digest);
    return Command::SUCCESS;
})->purpose('Create an inert, content-addressed fictional release bundle in private test storage');


/**
 * F1-37: assemble a wholly fictional review handoff in private CI scratch.
 * No AI provider, database, network, real Portal approval, or publishing.
 */
Artisan::command('sites:review-fixture {product=onepage}', function (): int {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_REVIEW') !== '1') {
        $this->error('Review candidate requires explicit synthetic CLI testing flag.');
        return Command::FAILURE;
    }

    $product=(string)$this->argument('product');
    if (!in_array($product,['onepage','landing_page'],true)) {
        $this->error('Unsupported fictional review product.');
        return Command::FAILURE;
    }

    $source=base_path('../../fixtures/sites/f1-37-copy.synthetic.json');
    if (!is_file($source)) {
        $this->error('Missing synthetic copy fixture.');
        return Command::FAILURE;
    }

    try {
        $copy=json_decode((string)file_get_contents($source),true,16,JSON_THROW_ON_ERROR);
        $brief=\Imagine\Sites\Production\StructuredCopyCandidate::assemble(
            $product,'Marca Fictícia Imagine',$copy
        );
        $artifact=(new StaticSiteRenderer())->render($brief);
        if (!\Imagine\Sites\Production\InertPreviewAudit::isSafe($artifact->html)) {
            $this->error('HTML is outside the strict inert preview audit.');
            return Command::FAILURE;
        }

        $budget=\Imagine\Sites\Production\SyntheticCreditLedger::create(
            ['tenant-fixture'=>2],1
        )->reserve('tenant-fixture','operation-f37-'.$product,'workflow-f37-'.$product,1);
        if ($budget->remaining('tenant-fixture')!==1) {
            $this->error('Unexpected fictional credit ledger result.');
            return Command::FAILURE;
        }

        $workflow=\Imagine\Sites\Production\SyntheticProductionWorkflow::create(
            'tenant-fixture','site-fixture','workflow-f37-'.$product
        );
        $workflow=$workflow->advance('agent','start_draft',0);
        $workflow=$workflow->advance('agent','submit_draft',1,$artifact->digest);
        $workflow=$workflow->advance('imagine_reviewer','approve_internal',2,$artifact->digest);

        $releaseRef='release-f37-'.$product;
        $candidate=$workflow->portalReviewCandidate($releaseRef,$artifact->digest,3);
        if (!\Imagine\Sites\Production\SyntheticReviewCandidateGuard::matchesLocalSnapshot(
            $candidate,$workflow,$releaseRef
        )) {
            $this->error('Review handoff candidate was not an exact local match.');
            return Command::FAILURE;
        }

        $manifest=new \Imagine\Sites\Domain\ReleaseManifest(
            'tenant-fixture','site-fixture',$releaseRef,$artifact->digest
        );
        $bundle=new \Imagine\Sites\Production\SyntheticReleaseBundle($manifest,$artifact);
        if (!\Imagine\Sites\Production\SyntheticReleaseBundle::verifyFiles(
            $bundle->files,$manifest
        ) || $candidate['publishable']!==false ||
            $candidate['officialClientAcceptance']!==false) {
            $this->error('Synthetic bundle/candidate must remain non-publishable.');
            return Command::FAILURE;
        }

        $candidateJson=json_encode(
            $candidate,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
        )."\n";
    } catch (\Throwable $e) {
        $this->error('Invalid fictional review fixture or failed local validation.');
        return Command::FAILURE;
    }

    $private=storage_path('app/private');
    $directory=$private.'/sites-f1-37-'.$product;
    if (is_link($private) || is_link($directory) || file_exists($directory)) {
        $this->error('Private target already exists or is unsafe.');
        return Command::FAILURE;
    }
    if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
        $this->error('Private scratch directory unavailable.');
        return Command::FAILURE;
    }
    if (!mkdir($directory,0700)) {
        $this->error('Unable to create fresh review scratch directory.');
        return Command::FAILURE;
    }
    @chmod($directory,0700);
    $files=$bundle->files+['portal-review-candidate.json'=>$candidateJson];
    foreach ($files as $filename=>$bytes) {
        // Filenames originate exclusively from trusted constants above.
        $path=$directory.'/'.$filename;
        $stream=@fopen($path,'x');
        if ($stream===false) {
            $this->error('Unable to write a fresh private fixture file.');
            return Command::FAILURE;
        }
        $written=fwrite($stream,$bytes);
        fclose($stream);
        @chmod($path,0600);
        if ($written!==strlen($bytes)) {
            $this->error('Failed to persist exact fixture bytes.');
            return Command::FAILURE;
        }
    }
    $this->line('F1-37 PRIVATE FICTIONAL REVIEW CANDIDATE ONLY — NOT DELIVERED.');
    $this->line('HTML SHA-256: '.$artifact->digest);
    $this->line('Bundle SHA-256: '.$bundle->bundleDigest);
    $this->line('Portal official client acceptance: false; publishable: false.');
    return Command::SUCCESS;
})->purpose('Generate a non-operational, private, fictional Laravel review handoff');


/**
 * F1-374: read-only staging preflight. This always returns NO-GO until
 * verified commercial, IAM, backup and AppSec gates are implemented and
 * reviewed independently. It never mutates the database or changes routes.
 */
Artisan::command('sites:homologation-preflight', function (): int {
    if (PHP_SAPI !== 'cli') {
        $this->error('Homologation preflight is CLI-only.');
        return Command::FAILURE;
    }

    $connection = config('database.connections.pgsql', []);
    $environmentVariables = getenv();
    $unsafeFlags = false;
    if (is_array($environmentVariables)) {
        foreach ($environmentVariables as $name=>$value) {
            if (is_string($name) && is_string($value) &&
                preg_match('/\ASITES_(?:SYNTHETIC_[A-Z0-9_]*|F[0-9]+_[A-Z0-9_]*LAB)\z/D',$name) &&
                $value === '1') {
                $unsafeFlags = true;
                break;
            }
        }
    }

    $runtime = [
        'environment'=>(string) config('app.env'),
        'debug'=>(bool) config('app.debug'),
        'app_key'=>config('app.key'),
        'app_url'=>config('app.url'),
        'synthetic_flags_disabled'=>!$unsafeFlags,
        'db_driver'=>config('database.default'),
        'db_host'=>$connection['host'] ?? null,
        'db_name'=>$connection['database'] ?? null,
    ];

    $probe = static function (): array {
        $database=\Illuminate\Support\Facades\DB::connection('pgsql');
        $details=$database->selectOne(
            "SELECT current_database() AS db_name,
                    current_setting('server_version_num')::integer AS pg_version"
        );
        $privileges=$database->selectOne(
            'SELECT rolsuper,rolcreatedb,rolcreaterole
               FROM pg_roles WHERE rolname=current_user'
        );
        $ssl=$database->selectOne(
            'SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()'
        );
        $boolean=static fn (mixed $v): bool =>
            in_array($v,[true,1,'1','t','true'],true);
        return [
            'db_name'=>$details->db_name ?? null,
            'pg_version'=>(int)($details->pg_version ?? 0),
            'is_superuser'=>$boolean($privileges->rolsuper ?? null),
            'can_create_db'=>$boolean($privileges->rolcreatedb ?? null),
            'can_create_role'=>$boolean($privileges->rolcreaterole ?? null),
            'tls'=>$boolean($ssl->ssl ?? null),
        ];
    };
    $report=\App\Support\HomologationReadiness::inspect($runtime,$probe);
    $this->line(json_encode(
        $report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
    ));
    // Intentionally NEVER changes any release gate to green.
    return Command::FAILURE;
})->purpose('Read-only NO-GO staging preflight with sanitized verification findings');


/**
 * F1-381: read-only operator artifact bundle from PG18 admitted fictional
 * projects. Emits JSON only to the caller's private CI stdout. It never
 * writes to public storage or allows a client's approval.
 */
Artisan::command('sites:admitted-review-fixture', function (): int {
    try {
        $bundle=(new \App\Labs\LaravelAdmittedPreviewLab(
            \Illuminate\Support\Facades\DB::connection('pgsql')
        ))->build();
        $this->line(json_encode(
            $bundle,
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        ));
        return Command::SUCCESS;
    } catch (\Throwable) {
        $this->error('F1-381: review fixture unavailable; no public output.');
        return Command::FAILURE;
    }
})->purpose('Emit a private CI-only visual review bundle; never publish or accept clients');
