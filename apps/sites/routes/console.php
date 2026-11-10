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


/**
 * F1-58: 12 synthetic PRIVATE theme + viewport preview pages.
 * Separate from F1-41 legacy Classic-only gallery; no public routes.
 * Theme selection is fictional visual review, NEVER client's acceptance.
 */
Artisan::command('sites:export-style-gallery-fixture', function (): int {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP')!=='1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI')!=='1' ||
        getenv('SITES_SYNTHETIC_STYLE_EXPORT')!=='1' ||
        getenv('SITES_SYNTHETIC_EXPORT')==='1') {
        $this->error('F1-58 requires three explicit lab switches; legacy export must be off.');
        return Command::FAILURE;
    }
    $private=storage_path('app/private');
    $directory=$private.'/sites-f1-58-style-gallery';
    if (is_link($private) || is_link($directory) ||
        file_exists($directory) || is_dir($directory)) {
        $this->error('F1-58 private style gallery must have a fresh, safe target.');
        return Command::FAILURE;
    }
    $products=['onepage','landing_page'];
    $devices=['desktop','mobile'];
    $styles=['classic','modern','warm'];
    $pages=[];
    $catalog=[];
    try {
        foreach ($products as $product) {
            foreach ($devices as $device) {
                foreach ($styles as $preset) {
                    $url='/_lab/sites/v1/review?product='.$product.
                        '&device='.$device.'&preset='.$preset;
                    $request=\Illuminate\Http\Request::create(
                        $url,'GET',[],[],[],['REMOTE_ADDR'=>'127.0.0.1']
                    );
                    // Call only an already registered route inside same
                    // CLI/testing kernel. No Laravel HTTP server is started.
                    $prior=app('request');
                    try {
                        app()->instance('request',$request);
                        $matched=app('router')->getRoutes()->match($request);
                        $matched->bind($request);
                        $response=$matched->run();
                    } finally {
                        app()->instance('request',$prior);
                    }
                    $html=(string)$response->getContent();
                    if ($response->getStatusCode()!==200 ||
                        $response->headers->get('X-Frame-Options')!=='DENY' ||
                        !str_contains((string)$response->headers->get('Cache-Control'),'no-store') ||
                        !str_contains($html,'sandbox=""') ||
                        !str_contains($html,'tabindex="0"') ||
                        substr_count($html,'href="/_lab/sites/v1/review?')!==7 ||
                        !preg_match('/id="artifact-digest">([a-f0-9]{64})<\/code>/',$html,$m) ||
                        !str_contains($html,'release-f38-'.$product.
                            ($preset==='classic'?'':'-'.$preset))) {
                        throw new \DomainException('Unsafe preview or unexpected lab navigation.');
                    }
                    $artifactDigest=$m[1];
                    $matches=[];
                    if (!preg_match('/\bsrcdoc="([^"]+)"/s',$html,$matches) ||
                        !hash_equals($artifactDigest,hash('sha256',
                            html_entity_decode($matches[1],ENT_QUOTES|ENT_HTML5,'UTF-8')))) {
                        throw new \DomainException('Preview HTML and release digest mismatch.');
                    }
                    $converted=0;
                    foreach ($products as $p) {
                        foreach ($devices as $d) {
                            foreach ($styles as $s) {
                                $link='href="/_lab/sites/v1/review?product='.$p.
                                    '&amp;device='.$d.'&amp;preset='.$s.'"';
                                $target='href="'.$p.'-'.$d.'-'.$s.'.html"';
                                $count=0;
                                $html=str_replace($link,$target,$html,$count);
                                $converted+=$count;
                            }
                        }
                    }
                    if ($converted!==7 ||
                        str_contains($html,'href="/_lab/sites/v1/review?')) {
                        throw new \DomainException('Gallery contains unresolved navigation.');
                    }
                    $csp='<meta http-equiv="Content-Security-Policy" content="default-src '.
                        "'none'; script-src 'none'; style-src 'unsafe-inline'; frame-src 'self'".
                        " about:; img-src data:; connect-src 'none'; form-action 'none'; object-src 'none';".
                        " base-uri 'none'; frame-ancestors 'none'".'">';
                    $robots='<meta name="robots" content="noindex, nofollow">';
                    if (substr_count($html,$robots)!==1) {
                        throw new \DomainException('Missing exact noindex token.');
                    }
                    $html=str_replace($robots,$robots."\n".$csp,$html);
                    if (preg_match('/<(?:script|form)\b/i',$html) ||
                        preg_match('/(?:https?:\/\/|javascript:|@import)/i',$html)) {
                        throw new \DomainException('Remote or executable preview refused.');
                    }
                    $filename=$product.'-'.$device.'-'.$preset.'.html';
                    $pages[$filename]=$html;
                    $catalog[]=[
                        'filename'=>$filename,
                        'product'=>$product,
                        'device'=>$device,
                        'preset'=>$preset,
                        'sha256'=>hash('sha256',$html),
                        'artifactDigest'=>$artifactDigest,
                        'bytes'=>strlen($html),
                    ];
                }
            }
        }
        if (count($pages)!==12 || count(array_unique(
            array_column($catalog,'filename')))!==12) {
            throw new \DomainException('Gallery must contain twelve unique pages.');
        }
        $meta=[
            'schemaVersion'=>'sites.synthetic.style-gallery.v1',
            'syntheticOnly'=>true,
            'publishable'=>false,
            'officialClientAcceptance'=>false,
            'pages'=>$catalog,
        ];
        $pages['gallery-manifest.json']=json_encode(
            $meta,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|
            JSON_UNESCAPED_UNICODE
        )."\n";
    } catch (\Throwable) {
        $this->error('F1-58 did not assemble all twelve safe synthetic previews.');
        return Command::FAILURE;
    }
    if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
        $this->error('Private destination unavailable.');
        return Command::FAILURE;
    }
    if (!mkdir($directory,0700)) {
        $this->error('Cannot create fresh private style gallery.');
        return Command::FAILURE;
    }
    @chmod($directory,0700);
    foreach ($pages as $filename=>$bytes) {
        $stream=@fopen($directory.'/'.$filename,'x');
        if ($stream===false) {
            $this->error('Cannot create exclusive gallery file.');
            return Command::FAILURE;
        }
        $offset=0;
        try {
            while ($offset<strlen($bytes)) {
                $written=fwrite($stream,substr($bytes,$offset));
                if ($written===false || $written===0) break;
                $offset+=$written;
            }
        } finally {
            fclose($stream);
        }
        @chmod($directory.'/'.$filename,0600);
        if ($offset!==strlen($bytes)) {
            $this->error('Incomplete gallery write rejected.');
            return Command::FAILURE;
        }
    }
    $this->line('F1-58 PRIVATE FICTIONAL GALLERY: 12 HTML previews, NO CLIENT ACCEPTANCE.');
    $this->line('Each page includes SHA-256 digest and local links; publishable=false.');
    return Command::SUCCESS;
})->purpose('Generate twelve inert private Onepage/Landing/theme/viewport review fixtures');


/**
 * F1-72: assemble an unapproved private draft from an explicitly selected
 * FICTIONAL input. Never accepts user-supplied paths, credentials or network.
 */
Artisan::command('sites:assemble-draft-fixture {product=onepage} {preset=classic} {variant=basic}', function (): int {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_DRAFT') !== '1') {
        $this->error('Draft assembly requires explicit CLI synthetic testing mode.');
        return Command::FAILURE;
    }
    $product=(string)$this->argument('product');
    $preset=(string)$this->argument('preset');
    $variant=(string)$this->argument('variant');
    if (!in_array($product,['onepage','landing_page'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !in_array($variant,['basic','focused'],true)) {
        $this->error('Unsupported fixture product, preset or variant.');
        return Command::FAILURE;
    }

    // Exact fixture paths only: no caller-provided file or directory.
    $source=base_path('../../fixtures/sites/f1-72-'.$variant.'.synthetic.json');
    $private=storage_path('app/private');
    $directory=$private.'/sites-f1-72-'.$product.'-'.$preset.'-'.$variant;
    if (is_link($private) || is_link($directory) ||
        file_exists($directory) || is_dir($directory) ||
        !is_file($source) || is_link($source)) {
        $this->error('Missing input or private target already exists/unsafe.');
        return Command::FAILURE;
    }

    try {
        $raw=(string)file_get_contents($source);
        if (strlen($raw)>4096 || strlen($raw)===0) {
            throw new \DomainException('Input fixture outside strict budget.');
        }
        $copy=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || array_is_list($copy)) {
            throw new \DomainException('Copy fixture must be a JSON object.');
        }
        $assembly=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            'tenant-fixture','site-fixture',
            'workflow-f72-'.$product.'-'.$preset.'-'.$variant,
            'release-f72-'.$product.'-'.$preset.'-'.$variant,
        );
        $summary=$assembly->reviewSummary();
        if ($summary['publishable']!==false ||
            $summary['officialClientAcceptance']!==false ||
            $summary['internalReviewRequired']!==true ||
            $summary['workflowState']!=='internal_review') {
            throw new \DomainException('Internal-only draft invariant violated.');
        }
        $dossier=\Imagine\Sites\Production\SyntheticInternalReviewDossier::fromDraft(
            $assembly
        );
        if ($dossier->record['reviewerDecisionStatus']!=='pending' ||
            $dossier->record['publishable']!==false ||
            $dossier->record['officialClientAcceptance']!==false) {
            throw new \DomainException('Review dossier cannot implicitly approve a draft.');
        }
        $summary['reviewDossierDigest']=$dossier->digest;
        $files=$assembly->bundle->files+[
            'internal-review-draft.json'=>json_encode(
                $summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE
            )."\n",
            'internal-review-dossier.json'=>$dossier->json,
        ];
        if (array_keys($files)!==[
            'index.html','release-manifest.json','internal-review-draft.json',
            'internal-review-dossier.json',
        ]) {
            throw new \DomainException('Unexpected local output file inventory.');
        }
    } catch (\Throwable) {
        $this->error('Synthetic draft validation failed.');
        return Command::FAILURE;
    }

    if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
        $this->error('Unable to create private lab scratch.');
        return Command::FAILURE;
    }
    if (is_link($private) || !mkdir($directory,0700)) {
        $this->error('Unable to create a fresh private draft output.');
        return Command::FAILURE;
    }
    @chmod($directory,0700);
    foreach ($files as $filename=>$bytes) {
        $output=$directory.'/'.$filename;
        $stream=@fopen($output,'x');
        if ($stream===false) {
            $this->error('Unable to create private draft file.');
            return Command::FAILURE;
        }
        $n=fwrite($stream,$bytes);
        fclose($stream);
        @chmod($output,0600);
        if ($n!==strlen($bytes)) {
            $this->error('Incomplete private draft file write.');
            return Command::FAILURE;
        }
    }
    $this->line('PRIVATE SYNTHETIC DRAFT — INTERNAL REVIEW REQUIRED; NOT DELIVERED.');
    $this->line('HTML SHA-256: '.$assembly->artifact->digest);
    $this->line('Bundle SHA-256: '.$assembly->bundle->bundleDigest);
    $this->line('Release: '.$assembly->manifest->releaseRef);
    return Command::SUCCESS;
})->purpose('Export a fictional, private structured draft without approval or publication');


/**
 * F1-86: independently re-read the FOUR files of a fictional draft exported by
 * F1-72/F1-77. The product/theme/variant are fixed allowlisted options;
 * there is NO arbitrary user path, upload, approval, write or publication.
 */
Artisan::command('sites:verify-draft-fixture {product=onepage} {preset=classic} {variant=basic}', function (): int {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_VERIFY') !== '1') {
        $this->error('Private draft verification requires explicit CLI testing mode.');
        return Command::FAILURE;
    }
    $product=(string)$this->argument('product');
    $preset=(string)$this->argument('preset');
    $variant=(string)$this->argument('variant');
    if (!in_array($product,['onepage','landing_page'],true) ||
        !in_array($preset,['classic','modern','warm'],true) ||
        !in_array($variant,['basic','focused'],true)) {
        $this->error('Invalid fictional draft selector.');
        return Command::FAILURE;
    }
    $private=storage_path('app/private');
    if (is_link($private) || !is_dir($private)) {
        $this->error('Private draft storage is absent or unsafe.');
        return Command::FAILURE;
    }
    $directory=$private.'/sites-f1-72-'.$product.'-'.$preset.'-'.$variant;
    try {
        $proof=\Imagine\Sites\Production\SyntheticPrivateDraftVerifier::verifyDirectory(
            $directory
        );
        if ($proof['publishable']!==false ||
            $proof['officialClientAcceptance']!==false ||
            $proof['reviewerDecisionStatus']!=='pending') {
            throw new \DomainException('Verification cannot grant release authority');
        }
    } catch (\Throwable) {
        $this->error('Private draft verification DENIED — no approval or publication.');
        return Command::FAILURE;
    }
    // The result has no signature or trusted identity; do not use as a Portal
    // authorization or store it as an official customer acceptance event.
    $this->line('PASS: PRIVATE SYNTHETIC DRAFT VERIFIED; HUMAN REVIEW PENDING.');
    $this->line('Release: '.$proof['releaseRef']);
    $this->line('HTML SHA-256: '.$proof['artifactDigest']);
    $this->line('Dossier SHA-256: '.$proof['dossierDigest']);
    $this->line('Client acceptance: NO; publishable: NO.');
    return Command::SUCCESS;
})->purpose('Read-only validation of four fictional draft files; cannot approve or publish');
