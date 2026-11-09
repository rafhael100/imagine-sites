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
Artisan::command('sites:review-fixture {product=onepage} {preset=classic}', function (): int {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_REVIEW') !== '1') {
        $this->error('Review candidate requires explicit synthetic CLI testing flag.');
        return Command::FAILURE;
    }

    $product=(string)$this->argument('product');
    $preset=(string)$this->argument('preset');
    if (!in_array($product,['onepage','landing_page'],true) ||
        !in_array($preset,['classic','modern','warm'],true)) {
        $this->error('Unsupported fictional review product or visual preset.');
        return Command::FAILURE;
    }

    $source=base_path('../../fixtures/sites/f1-37-copy.synthetic.json');
    if (!is_file($source)) {
        $this->error('Missing synthetic copy fixture.');
        return Command::FAILURE;
    }

    try {
        $copy=json_decode((string)file_get_contents($source),true,16,JSON_THROW_ON_ERROR);
        $copyBrief=\Imagine\Sites\Production\StructuredCopyCandidate::assemble(
            $product,'Marca Fictícia Imagine',$copy
        );
        // Keep copy candidate untrusted and preset independently whitelisted.
        // Brand/product/theme are NOT inferred from the AI text.
        $brief=new SiteBrief(
            $copyBrief->productCode,$copyBrief->brand,$copyBrief->headline,
            $copyBrief->description,$copyBrief->callToAction,
            $copyBrief->benefits,$preset
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

        $releaseRef='release-f37-'.$product.($preset==='classic'?'':'-'.$preset);
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
    $directory=$private.'/sites-f1-37-'.$product.($preset==='classic'?'':'-'.$preset);
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
    $this->line('F1-53 PRIVATE FICTIONAL REVIEW: '.$product.' / '.$preset.' — NOT DELIVERED.');
    $this->line('HTML SHA-256: '.$artifact->digest);
    $this->line('Bundle SHA-256: '.$bundle->bundleDigest);
    $this->line('Portal official client acceptance: false; publishable: false.');
    return Command::SUCCESS;
})->purpose('Generate a non-operational, private, fictional Laravel review handoff');


/**
 * F1-41: create a standalone, fully fictional local visual-review gallery.
 * This NEVER runs as HTTP, uses no real provider or customer content and
 * cannot approve/publish; the output exists only in private CI scratch.
 */
Artisan::command('sites:export-review-gallery-fixture', function (): int {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_EXPORT')!=='1') {
        $this->error('F1-41 requires explicit in-process CLI/testing lab flags.');
        return Command::FAILURE;
    }
    $directory=storage_path('app/private/sites-f1-41-gallery');
    $private=storage_path('app/private');
    if (is_link($private) || is_link($directory) ||
        file_exists($directory) || is_dir($directory)) {
        $this->error('F1-41 output must be a fresh, non-symlink private directory.');
        return Command::FAILURE;
    }

    $pages=[];
    try {
        foreach (['onepage','landing_page'] as $product) {
            foreach (['desktop','mobile'] as $device) {
                $path='/_lab/sites/v1/review?product='.$product.'&device='.$device;
                $request=\Illuminate\Http\Request::create(
                    $path,'GET',[],[],[],['REMOTE_ADDR'=>'127.0.0.1']
                );
                // The route action resolves Request through Laravel's
                // container. Rebind it for EACH synthetic viewport and restore
                // the previous request to avoid cross-preview state leakage.
                // No web middleware, session or network service is started.
                $priorRequest=app('request');
                try {
                    app()->instance('request',$request);
                    $labRoute=app('router')->getRoutes()->match($request);
                    $labRoute->bind($request);
                    $response=$labRoute->run();
                } finally {
                    app()->instance('request',$priorRequest);
                }
                $html=(string)$response->getContent();
                if ($response->getStatusCode()!==200 ||
                    $response->headers->get('X-Frame-Options')!=='DENY' ||
                    !str_contains((string)$response->headers->get('Cache-Control'),'no-store') ||
                    !str_contains($html,'sandbox=""') ||
                    !preg_match('/id="artifact-digest">[a-f0-9]{64}<\/code>/',$html)) {
                    throw new \DomainException('review route response not safe to export');
                }
                if (substr_count($html,'href="/_lab/sites/v1/review?')!==4) {
                    throw new \DomainException('unexpected lab navigation links');
                }
                foreach (['onepage','landing_page'] as $targetProduct) {
                    foreach (['desktop','mobile'] as $targetDevice) {
                        $link='href="/_lab/sites/v1/review?product='.$targetProduct.
                            '&amp;device='.$targetDevice.'"';
                        $html=str_replace(
                            $link,'href="'.$targetProduct.'-'.$targetDevice.'.html"',$html
                        );
                    }
                }
                if (str_contains($html,'href="/_lab/sites/v1/review?')) {
                    throw new \DomainException('unrewritten navigation');
                }
                $csp='<meta http-equiv="Content-Security-Policy" '.
                    'content="default-src '."'none'".'; script-src '."'none'".
                    '; style-src '."'unsafe-inline'".'; frame-src '."'self'".
                    ' about:; img-src data:; form-action '."'none'".'; object-src '.
                    "'none'".'; base-uri '."'none'".'">';
                if (substr_count($html,'<meta name="robots" content="noindex, nofollow">')!==1) {
                    throw new \DomainException('missing noindex review header');
                }
                $html=str_replace(
                    '<meta name="robots" content="noindex, nofollow">',
                    '<meta name="robots" content="noindex, nofollow">'."\n".$csp,
                    $html
                );
                if (preg_match('/<(?:script|form)\b/i',$html) ||
                    preg_match('/(?:https?:\/\/|javascript:)/i',$html)) {
                    throw new \DomainException('offline gallery has active/remote markup');
                }
                $filename=$product.'-'.$device.'.html';
                $pages[$filename]=$html;
            }
        }
        $metadata=[
            'schemaVersion'=>'sites.synthetic.visual-gallery.v1',
            'syntheticOnly'=>true,
            'publishable'=>false,
            'officialClientAcceptance'=>false,
            'pages'=>[],
        ];
        foreach ($pages as $name=>$bytes) {
            $metadata['pages'][]=[
                'filename'=>$name,'sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)
            ];
        }
        $pages['gallery-manifest.json']=json_encode(
            $metadata,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
        )."\n";
    } catch (\Throwable) {
        $this->error('Unable to assemble safe, fictional review HTML offline.');
        return Command::FAILURE;
    }

    if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
        $this->error('Private scratch parent missing.');
        return Command::FAILURE;
    }
    if (!mkdir($directory,0700)) {
        $this->error('Unable to create fresh private gallery directory.');
        return Command::FAILURE;
    }
    @chmod($directory,0700);
    foreach ($pages as $filename=>$bytes) {
        // Every filename is a trusted constant constructed from whitelists.
        $target=$directory.'/'.$filename;
        $stream=@fopen($target,'x');
        if ($stream===false) {
            $this->error('Unsafe or unavailable gallery file destination.');
            return Command::FAILURE;
        }
        $offset=0;
        while ($offset<strlen($bytes)) {
            $written=fwrite($stream,substr($bytes,$offset));
            if ($written===false || $written===0) break;
            $offset+=$written;
        }
        fclose($stream);
        @chmod($target,0600);
        if ($offset!==strlen($bytes)) {
            $this->error('Failed exact byte write in private gallery.');
            return Command::FAILURE;
        }
    }
    $this->line('F1-41 OFFLINE SYNTHETIC GALLERY ONLY — NO PUBLISH / NO CLIENT ACCEPTANCE.');
    $this->line('Four private HTML fixtures and one SHA-256 gallery manifest created.');
    return Command::SUCCESS;
})->purpose('Render a standalone fictional gallery in local private CI scratch');
