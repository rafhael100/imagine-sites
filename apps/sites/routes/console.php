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
    if (!\Imagine\Sites\Production\SyntheticReleaseBundle::verifyExactBundle(
        $bundle->files,$release,$bundle->bundleDigest
    )) {
        $this->error('Built package did not pass exact integrity policy.');
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
    // F1-296: verify bytes actually stored in private scratch, not only
    // the in-memory bundle. This is not external publication observation.
    $readback=[];
    foreach (array_keys($bundle->files) as $name) {
        $target=$directory.'/'.$name;
        if (is_link($target) || !is_file($target)) {
            $this->error('Private bundle readback is missing or unsafe.');
            return Command::FAILURE;
        }
        $bytes=file_get_contents($target);
        if ($bytes===false) {
            $this->error('Private bundle readback failed.');
            return Command::FAILURE;
        }
        $readback[$name]=$bytes;
    }
    if (!\Imagine\Sites\Production\SyntheticReleaseBundle::verifyExactBundle(
        $readback,$release,$bundle->bundleDigest
    )) {
        $this->error('Private bundle readback digest mismatch.');
        return Command::FAILURE;
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
    try {
        // Stage the EXACT four files in the fixed canonical order, verify
        // before/after the move and only then expose a complete private draft.
        $proof=\Imagine\Sites\Production\SyntheticPrivateDraftWriter::create(
            $directory,[
                'index.html'=>$files['index.html'],
                'internal-review-dossier.json'=>$files['internal-review-dossier.json'],
                'internal-review-draft.json'=>$files['internal-review-draft.json'],
                'release-manifest.json'=>$files['release-manifest.json'],
            ]
        );
        if ($proof['releaseRef']!==$assembly->manifest->releaseRef ||
            $proof['artifactDigest']!==$assembly->artifact->digest ||
            $proof['dossierDigest']!==$dossier->digest ||
            $proof['officialClientAcceptance']!==false ||
            $proof['publishable']!==false) {
            throw new \DomainException('Private draft readback differs from source.');
        }
    } catch (\Throwable) {
        $this->error('Private draft staging/readback failed: not delivered or published.');
        return Command::FAILURE;
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


/**
 * F1-162: export a complete fictional rich-review gallery from the SAME
 * in-process Laravel route used by the reviewer. No HTTP server, uploads,
 * real customer material, Portal approval, CRM, or public publication.
 */
Artisan::command('sites:export-rich-gallery-fixture', function (): int {
    if (PHP_SAPI !== 'cli' || !app()->environment('testing') ||
        getenv('SITES_SYNTHETIC_HTTP') !== '1' ||
        getenv('SITES_SYNTHETIC_REVIEW_UI') !== '1' ||
        getenv('SITES_SYNTHETIC_RICH_REVIEW') !== '1' ||
        getenv('SITES_SYNTHETIC_RICH_EXPORT') !== '1' ||
        getenv('SITES_SYNTHETIC_EXPORT') === '1' ||
        getenv('SITES_SYNTHETIC_STYLE_EXPORT') === '1') {
        $this->error('Rich gallery requires explicit isolated CLI testing flags.');
        return Command::FAILURE;
    }

    $private=storage_path('app/private');
    $destination=$private.'/sites-f1-162-rich-gallery';
    if (is_link($private) || is_link($destination) ||
        file_exists($destination) || is_dir($destination)) {
        $this->error('Existing or linked rich gallery destination is forbidden.');
        return Command::FAILURE;
    }

    $products=['onepage','landing_page'];
    $devices=['desktop','mobile'];
    $presets=['classic','modern','warm'];
    $route='/_lab/sites/v1/rich-review';
    $pages=[];
    $entries=[];
    try {
        foreach ($products as $product) {
            foreach ($devices as $device) {
                foreach ($presets as $preset) {
                    $request=\Illuminate\Http\Request::create(
                        $route.'?product='.$product.'&device='.$device.'&preset='.$preset,
                        'GET',[],[],[],['REMOTE_ADDR'=>'127.0.0.1']
                    );
                    $previous=app('request');
                    try {
                        app()->instance('request',$request);
                        $matched=app('router')->getRoutes()->match($request);
                        $matched->bind($request);
                        $response=$matched->run();
                    } finally {
                        app()->instance('request',$previous);
                    }

                    $html=(string)$response->getContent();
                    if ($response->getStatusCode()!==200 ||
                        $response->headers->get('X-Frame-Options')!=='DENY' ||
                        !str_contains((string)$response->headers->get('Cache-Control'),'no-store') ||
                        substr_count($html,'href="'.$route.'?')!==7 ||
                        !str_contains($html,'sandbox=""') ||
                        !str_contains($html,'Conteúdo da página ampliado') ||
                        !preg_match('/id="artifact-digest">([a-f0-9]{64})<\/code>/',$html,$m) ||
                        !str_contains($html,'release-f161-'.$product.'-'.$preset)) {
                        throw new \DomainException('Rich review route returned invalid candidate.');
                    }

                    $digest=$m[1];
                    $found=[];
                    if (!preg_match('/\bsrcdoc="([^"]+)"/s',$html,$found)) {
                        throw new \DomainException('Sandboxed rich HTML missing.');
                    }
                    $inner=html_entity_decode($found[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                    if (!hash_equals($digest,hash('sha256',$inner)) ||
                        substr_count($inner,'<section class="faq"')!==1 ||
                        substr_count($inner,'<section class="highlights"')!==1 ||
                        str_contains($inner,'<script') ||
                        str_contains($inner,'<form')) {
                        throw new \DomainException('Rich inner HTML differs from approved draft bytes.');
                    }

                    $rewritten=0;
                    foreach ($products as $p) {
                        foreach ($devices as $d) {
                            foreach ($presets as $s) {
                                $search='href="'.$route.'?product='.$p.
                                    '&amp;device='.$d.'&amp;preset='.$s.'"';
                                $replacement='href="'.$p.'-'.$d.'-'.$s.'.html"';
                                $count=0;
                                $html=str_replace($search,$replacement,$html,$count);
                                $rewritten+=$count;
                            }
                        }
                    }
                    if ($rewritten!==7 || str_contains($html,'href="'.$route.'?')) {
                        throw new \DomainException('In-process gallery has unresolved navigation.');
                    }

                    $robots='<meta name="robots" content="noindex, nofollow">';
                    $csp='<meta http-equiv="Content-Security-Policy" content="default-src '.
                        "'none'; script-src 'none'; style-src 'unsafe-inline'; frame-src 'self'".
                        " about:; img-src data:; connect-src 'none'; form-action 'none'; object-src".
                        " 'none'; base-uri 'none'; frame-ancestors 'none'".'">';
                    if (substr_count($html,$robots)!==1) {
                        throw new \DomainException('Rich gallery lacks exact robots tag.');
                    }
                    $html=str_replace($robots,$robots."\n".$csp,$html);
                    if (preg_match('/<(?:script|form)\b/i',$html) ||
                        preg_match('/(?:https?:\/\/|javascript:|@import)/i',$html)) {
                        throw new \DomainException('Rich gallery must be fully inert and offline.');
                    }

                    $name=$product.'-'.$device.'-'.$preset.'.html';
                    $pages[$name]=$html;
                    $entries[]=[
                        'filename'=>$name,'product'=>$product,'device'=>$device,
                        'preset'=>$preset,'sha256'=>hash('sha256',$html),
                        'artifactDigest'=>$digest,'bytes'=>strlen($html),
                    ];
                }
            }
        }
        if (count($pages)!==12 || count(array_unique(array_keys($pages)))!==12) {
            throw new \DomainException('Rich review gallery needs twelve unique pages.');
        }
        $metadata=[
            'schemaVersion'=>'sites.synthetic.rich-review-gallery.v1',
            'syntheticOnly'=>true,'publishable'=>false,
            'officialClientAcceptance'=>false,'pages'=>$entries,
        ];
        $pages['gallery-manifest.json']=json_encode(
            $metadata,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        )."\n";
    } catch (\Throwable) {
        $this->error('Rich gallery build failed closed; no files exported.');
        return Command::FAILURE;
    }

    if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
        $this->error('Private scratch root unavailable.');
        return Command::FAILURE;
    }
    if (is_link($private) || !is_dir($private) || is_link($destination) ||
        file_exists($destination)) {
        $this->error('Unsafe private gallery destination.');
        return Command::FAILURE;
    }

    $stage=$private.'/.sites-f1-162-stage-'.bin2hex(random_bytes(10));
    if (!mkdir($stage,0700)) {
        $this->error('Cannot create private staging directory.');
        return Command::FAILURE;
    }
    $committed=false;
    try {
        foreach ($pages as $name=>$bytes) {
            $file=$stage.'/'.$name;
            $stream=@fopen($file,'x');
            if ($stream===false) {
                throw new \DomainException('Exclusive file creation failed.');
            }
            $offset=0;
            try {
                while ($offset<strlen($bytes)) {
                    $wrote=fwrite($stream,substr($bytes,$offset));
                    if ($wrote===false || $wrote===0) {
                        throw new \DomainException('Incomplete private HTML write.');
                    }
                    $offset+=$wrote;
                }
                if (!fflush($stream)) {
                    throw new \DomainException('Private HTML flush failed.');
                }
            } finally {fclose($stream);}
            if (!chmod($file,0600) ||
                !hash_equals(hash('sha256',$bytes),(string)hash_file('sha256',$file))) {
                throw new \DomainException('Private HTML readback mismatch.');
            }
        }

        if (is_link($destination) || file_exists($destination) ||
            !rename($stage,$destination)) {
            throw new \DomainException('Exclusive atomic gallery rename denied.');
        }
        $committed=true;
        foreach ($pages as $name=>$bytes) {
            if (!is_file($destination.'/'.$name) ||
                !hash_equals(hash('sha256',$bytes),
                    (string)hash_file('sha256',$destination.'/'.$name))) {
                throw new \DomainException('Committed gallery readback mismatch.');
            }
        }
    } catch (\Throwable) {
        $this->error('Rich gallery failed atomic write/readback.');
        return Command::FAILURE;
    } finally {
        if (!$committed && is_dir($stage) && !is_link($stage)) {
            foreach (array_keys($pages) as $name) {
                if (is_file($stage.'/'.$name)) @unlink($stage.'/'.$name);
            }
            @rmdir($stage);
        }
    }

    $this->line('F1-162 PRIVATE RICH REVIEW: 12 fictional pages, exact SHA-256.');
    $this->line('No real client acceptance, CRM, network, public site or deployment.');
    return Command::SUCCESS;
})->purpose('Atomically export twelve non-publishable rich review pages to private CI');


/**
 * F1-174: persist a reviewed-content PATCH as an entirely NEW, nonpublishable
 * PRIVATE synthetic review bundle. This is not a client editor or approval.
 */
Artisan::command('sites:export-editorial-package-fixture {product=onepage} {preset=classic}', function (): int {
    if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
        getenv('GITHUB_ACTIONS')!=='true' ||
        getenv('SITES_SYNTHETIC_EDITORIAL_REVIEW')!=='1' ||
        getenv('SITES_F174_EDITORIAL_PACKAGE')!=='1') {
        $this->error('Edited package requires isolated GitHub CLI/testing flags.');
        return Command::FAILURE;
    }
    $product=(string)$this->argument('product');
    $preset=(string)$this->argument('preset');
    if (!in_array($product,['onepage','landing_page'],true) ||
        !in_array($preset,['classic','modern','warm'],true)) {
        $this->error('Unsupported fictional product or theme.');
        return Command::FAILURE;
    }

    $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
    $private=storage_path('app/private');
    $destination=$private.'/sites-f1-174-'.$product.'-'.$preset.'-edited';
    if (!is_file($fixture) || is_link($fixture) ||
        is_link($private) || is_link($destination) ||
        file_exists($destination) || is_dir($destination)) {
        $this->error('Unsafe fixture or existing edited package destination.');
        return Command::FAILURE;
    }
    try {
        $raw=(string)file_get_contents($fixture);
        if ($raw==='' || strlen($raw)>8192) {
            throw new \DomainException('Invalid fixture budget.');
        }
        $copy=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || array_is_list($copy) ||
            ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
            count($copy['faq']??[])!==2 ||
            count($copy['processSteps']??[])!==3) {
            throw new \DomainException('Fictional v4 fixture required.');
        }
        $tenant='tenant-f174';
        $site='site-f174';
        $beforeRef='release-f174-before-'.$product.'-'.$preset;
        $afterRef='release-f174-after-'.$product.'-'.$preset;

        $source=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$copy,
            $tenant,$site,'workflow-f174-before-'.$product.'-'.$preset,$beforeRef
        );
        $approved=$source->workflow->advance(
            'imagine_reviewer','approve_internal',2,$source->artifact->digest
        );

        $faq=$copy['faq'];
        $faq[0]['answer']='O texto demonstrativo foi editado pela equipe Imagine e precisa de uma nova revisão.';
        $steps=$copy['processSteps'];
        $steps[1]['description']='Uma alteração editorial fictícia gera outra versão, sem herdar aprovações anteriores.';
        $changes=['faq'=>$faq,'processSteps'=>$steps];
        $patch=[
            'schemaVersion'=>'sites.editorial.patch.v1',
            'expectedDigest'=>$source->artifact->digest,
            'expectedRevision'=>$approved->revision,
            'changes'=>$changes,
        ];
        $plan=\Imagine\Sites\Production\SyntheticEditorialPatchV1::propose(
            $approved,$source->brief,$source->artifact,
            $beforeRef,$afterRef,'imagine_reviewer',$patch
        );
        $after=array_replace($copy,$changes);
        $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
            $product,'Marca Fictícia Imagine',$preset,$after,
            $tenant,$site,'workflow-f174-after-'.$product.'-'.$preset,$afterRef
        );
        if ($plan->changedFields!==['faq','processSteps'] ||
            $plan->draftWorkflow->artifactDigest!==null ||
            !hash_equals($plan->nextArtifact->digest,$draft->artifact->digest) ||
            !hash_equals($plan->nextArtifact->html,$draft->artifact->html)) {
            throw new \DomainException('Edited preview bytes diverge from patch.');
        }

        // F1-185: the EXACT same validated pending-review canonical
        // inventory is used by edited-copy and combined-layout packages.
        // No separate serialization logic may drift between the two paths.
        $files=\Imagine\Sites\Production\SyntheticPrivateReviewPackageV1::fromPendingDraft($draft);
        if (!is_dir($private) && !mkdir($private,0700,true) && !is_dir($private)) {
            throw new \DomainException('Private storage root unavailable.');
        }
        $proof=\Imagine\Sites\Production\SyntheticPrivateDraftWriter::create(
            $destination,$files
        );
        if ($proof['releaseRef']!==$afterRef ||
            !hash_equals($proof['artifactDigest'],$draft->artifact->digest) ||
            !hash_equals($proof['dossierDigest'],
                hash('sha256',$files['internal-review-dossier.json'])) ||
            $proof['reviewerDecisionStatus']!=='pending' ||
            $proof['publishable']!==false ||
            $proof['officialClientAcceptance']!==false) {
            throw new \DomainException('Edited package readback failed.');
        }
    } catch (\Throwable) {
        $this->error('Edited review package failed closed; no publication occurred.');
        return Command::FAILURE;
    }
    $this->line('F1-174 PRIVATE EDITED DRAFT: reviewer decision PENDING; not publishable.');
    $this->line('Previous SHA-256: '.$source->artifact->digest);
    $this->line('Edited SHA-256: '.$draft->artifact->digest);
    $this->line('Release: '.$afterRef);
    return Command::SUCCESS;
})->purpose('Write a fictional edited page and pending review dossier to private CI');


/**
 * F1-184: persist an exact combination of section order and optional Onepage
 * navigation as four private, content-addressed, pending-review files.
 *
 * The argument vocabulary is CLOSED. This never accepts a tenant, file path,
 * human identity, raw HTML or client data from command-line arguments.
 */
Artisan::command(
    'sites:export-layout-review-fixture {product=onepage} {preset=classic} {order=services_process_faq} {navigation=off}',
    function (): int {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F184_LAYOUT_PACKAGE')!=='1') {
            $this->error('Layout package disabled outside CI-only synthetic lab.');
            return Command::FAILURE;
        }
        $product=(string)$this->argument('product');
        $preset=(string)$this->argument('preset');
        $orderCode=(string)$this->argument('order');
        $navigation=(string)$this->argument('navigation');
        $orders=[
            'services_process_faq'=>['highlights','process','faq'],
            'services_faq_process'=>['highlights','faq','process'],
            'process_services_faq'=>['process','highlights','faq'],
            'process_faq_services'=>['process','faq','highlights'],
            'faq_services_process'=>['faq','highlights','process'],
            'faq_process_services'=>['faq','process','highlights'],
        ];
        if (!in_array($product,['onepage','landing_page'],true) ||
            !in_array($preset,['classic','modern','warm'],true) ||
            !array_key_exists($orderCode,$orders) ||
            !in_array($navigation,['on','off'],true) ||
            ($product==='landing_page' && $navigation==='on')) {
            $this->error('Unsupported fixed synthetic layout combination.');
            return Command::FAILURE;
        }

        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        $root=storage_path('app/private');
        $base='sites-f1-184-'.$product.'-'.$preset.'-'.$orderCode.'-'.$navigation;
        $destination=$root.'/'.$base;

        if (!is_file($fixture) || is_link($fixture) ||
            filesize($fixture)>8192 ||
            is_link($root) || is_link($destination) ||
            file_exists($destination)) {
            $this->error('Fixture or destination denied by private lab policy.');
            return Command::FAILURE;
        }

        try {
            $copy=json_decode((string)file_get_contents($fixture),true,
                16,JSON_THROW_ON_ERROR);
            if(!is_array($copy) || array_is_list($copy) ||
                ($copy['schemaVersion']??null)!=='sites.ai-copy.v4' ||
                count($copy['faq']??[])!==2 ||
                count($copy['highlights']??[])!==2 ||
                count($copy['processSteps']??[])!==3) {
                throw new \DomainException('Unexpected fixed v4 content source.');
            }
            $releaseRef='release-f184-'.$product.'-'.$preset.'-'.$orderCode.'-'.$navigation;
            $draft=\Imagine\Sites\Production\SyntheticStructuredDraftAssembly::build(
                $product,'Marca Fictícia Imagine',$preset,$copy,
                'tenant-f184','site-f184',
                'workflow-f184-'.$product.'-'.$preset.'-'.$orderCode.'-'.$navigation,
                $releaseRef,$orders[$orderCode],$navigation==='on',
            );
            if ($draft->brief->sectionOrder!==$orders[$orderCode] ||
                $draft->brief->sectionNavigation!==($navigation==='on') ||
                $draft->reviewSummary()['workflowState']!=='internal_review' ||
                $draft->reviewSummary()['publishable']!==false ||
                $draft->reviewSummary()['officialClientAcceptance']!==false) {
                throw new \DomainException('Only fixed pending-review layouts can be packaged.');
            }

            $files=\Imagine\Sites\Production\SyntheticPrivateReviewPackageV1::fromPendingDraft($draft);
            if (!is_dir($root) &&
                !mkdir($root,0700,true) && !is_dir($root)) {
                throw new \DomainException('Private storage root missing.');
            }
            if (is_link($root) || !is_dir($root)) {
                throw new \DomainException('Unsafe private storage root.');
            }
            $proof=\Imagine\Sites\Production\SyntheticPrivateDraftWriter::create(
                $destination,$files
            );
            if ($proof['releaseRef']!==$releaseRef ||
                $proof['artifactDigest']!==$draft->artifact->digest ||
                $proof['reviewerDecisionStatus']!=='pending' ||
                $proof['publishable']!==false ||
                $proof['officialClientAcceptance']!==false) {
                throw new \DomainException('Layout review package proof invalid.');
            }
        } catch (\Throwable) {
            $this->error('Synthetic layout review package failed closed.');
            return Command::FAILURE;
        }

        $this->line('F1-184 PRIVATE LAYOUT DRAFT: pending review; not public or delivered.');
        $this->line('Release: '.$releaseRef);
        $this->line('SHA-256: '.$draft->artifact->digest);
        return Command::SUCCESS;
    }
)->purpose('Export a fixed fictional combined-layout review bundle to private CI');


/**
 * F1-205: take one finite synthetic choice from F1-204's catalog and
 * atomically store a reviewed-by-nobody four-file draft on a private runner.
 *
 * NEVER accepts client text, destination paths, organization IDs or secrets.
 */
Artisan::command(
    'sites:export-visual-fixture {product=onepage} {preset=classic} {order=services_process_faq} {palette=default} {navigation=off} {social=off}',
    function (): int {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F205_VISUAL_PACKAGE')!=='1') {
            $this->error('F1-205 visual package is available only in offline CI.');
            return Command::FAILURE;
        }

        $product=(string)$this->argument('product');
        $preset=(string)$this->argument('preset');
        $orderCode=(string)$this->argument('order');
        $paletteCode=(string)$this->argument('palette');
        $navCode=(string)$this->argument('navigation');
        $socialCode=(string)$this->argument('social');
        if (!in_array($socialCode,['off','on'],true)) {
            $this->error('Unknown social metadata mode; no files created.');
            return Command::FAILURE;
        }
        try {
            \Imagine\Sites\Production\SyntheticVisualPresetCatalog::choose(
                $product,$preset,$orderCode,$navCode,$paletteCode
            );
        }catch(\InvalidArgumentException){
            $this->error('Unknown finite visual choice; no files created.');
            return Command::FAILURE;
        }

        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        $root=storage_path('app/private');
        $destination=$root.'/sites-f1-205-'.$product.'-'.$preset.'-'.
            $orderCode.'-'.$paletteCode.'-'.$navCode.
            ($socialCode==='on'?'-social-on':'');
        if (!is_file($fixture) || is_link($fixture) ||
            filesize($fixture)>8192 || is_link($root) ||
            is_link($destination) || file_exists($destination)) {
            $this->error('Untrusted fixture or private destination denied.');
            return Command::FAILURE;
        }

        try {
            $copy=json_decode((string)file_get_contents($fixture),true,
                16,JSON_THROW_ON_ERROR);
            $design=\Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture::build(
                $copy,$product,$preset,$orderCode,$navCode,$paletteCode,
                $socialCode==='on'
            );
            $files=$design->privateFiles();
            if ((!is_dir($root) &&
                    !mkdir($root,0700,true) && !is_dir($root)) ||
                !is_dir($root) || is_link($root)) {
                throw new \DomainException('Private runner directory unavailable.');
            }
            $proof=\Imagine\Sites\Production\SyntheticPrivateDraftWriter::create(
                $destination,$files
            );
            $expectedRelease='release-f205-'.$product.'-'.$preset.'-'.
                $orderCode.'-'.$paletteCode.'-'.$navCode.
                ($socialCode==='on'?'-social-on':'');
            if ($proof['releaseRef']!==$expectedRelease ||
                !hash_equals($proof['artifactDigest'],$design->draft->artifact->digest) ||
                $proof['reviewerDecisionStatus']!=='pending' ||
                $proof['publishable']!==false ||
                $proof['officialClientAcceptance']!==false) {
                throw new \DomainException('Private visual package readback denied.');
            }
        }catch(\Throwable){
            $this->error('Composite visual package denied. No publication occurred.');
            return Command::FAILURE;
        }

        $this->line('F1-205 PRIVATE VISUAL DRAFT: only synthetic internal review.');
        $this->line('Original SHA-256: '.$design->originalDigest);
        $this->line('Proposed SHA-256: '.$design->draft->artifact->digest);
        $this->line($design->changed
            ? 'Changed design; earlier fictional approval NOT reused.'
            : 'Original design; NO fabricated prior review.');
        return Command::SUCCESS;
    }
)->purpose('Export one fictional composite visual design to private CI review files');


/**
 * F1-211: re-open an EXISTING fictional visual package with verified bytes;
 * independently match its social title/description to the expected renderer.
 * This command never writes, republishes, retries, accepts or contacts CRM.
 */
Artisan::command(
    'sites:inspect-social-fixture {product=onepage} {preset=classic} {order=services_process_faq} {palette=default} {navigation=off} {social=off}',
    function (): int {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F211_SOCIAL_READBACK')!=='1') {
            $this->error('Private social readback disabled outside isolated CI.');
            return Command::FAILURE;
        }
        $product=(string)$this->argument('product');
        $preset=(string)$this->argument('preset');
        $order=(string)$this->argument('order');
        $palette=(string)$this->argument('palette');
        $navigation=(string)$this->argument('navigation');
        $social=(string)$this->argument('social');
        if(!in_array($social,['off','on'],true)) {
            $this->error('Unknown social metadata mode.');
            return Command::FAILURE;
        }
        try {
            \Imagine\Sites\Production\SyntheticVisualPresetCatalog::choose(
                $product,$preset,$order,$navigation,$palette
            );
        }catch(\InvalidArgumentException){
            $this->error('Unknown visual catalog option.');
            return Command::FAILURE;
        }

        $folder=storage_path('app/private/sites-f1-205-').
            $product.'-'.$preset.'-'.$order.'-'.$palette.'-'.$navigation.
            ($social==='on'?'-social-on':'');
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        try{
            if(!is_file($fixture) || is_link($fixture) ||
                filesize($fixture)>8192 ||
                !is_dir($folder) || is_link($folder)) {
                throw new \DomainException('Missing fixed fixture or package.');
            }
            // Verifier rejects symlink ancestors, unexpected file names,
            // extra hard links, tampering and forged acceptance metadata.
            $verified=\Imagine\Sites\Production\SyntheticPrivateDraftVerifier::verifyDirectory(
                $folder
            );
            $files=[];
            foreach([
                'index.html','internal-review-dossier.json',
                'internal-review-draft.json','release-manifest.json',
            ] as $name){
                $raw=file_get_contents($folder.'/'.$name);
                if(!is_string($raw)) {
                    throw new \DomainException('Incomplete private readback.');
                }
                $files[$name]=$raw;
            }
            // Reverify the actual bytes just read, not only the metadata
            // returned by the first check; this is not an atomic openat.
            $copy=json_decode((string)file_get_contents($fixture),
                true,16,JSON_THROW_ON_ERROR);
            $design=\Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture::build(
                $copy,$product,$preset,$order,$navigation,$palette,$social==='on'
            );
            $report=\Imagine\Sites\Production\SyntheticPrivateSocialCardReadbackV1::inspect(
                $files,$design->draft->brief
            );
            if(!hash_equals($report->artifactDigest,$verified['artifactDigest']) ||
                !hash_equals($report->artifactDigest,$design->draft->artifact->digest) ||
                $report->releaseRef!==$verified['releaseRef'] ||
                $report->releaseRef!==$design->draft->manifest->releaseRef ||
                $report->socialMetadataEnabled!==($social==='on') ||
                ($report->card!==null)!==($social==='on') ||
                $report->canPublish() ||
                $report->canMarkClientAccepted() ||
                $report->canSendToCrm() ||
                $report->canSharePublicly()) {
                throw new \DomainException('Stored social preview evidence inconsistent.');
            }
        }catch(\Throwable){
            $this->error('Private social readback denied. Nothing was shared.');
            return Command::FAILURE;
        }

        $this->line('F1-211 VERIFIED PRIVATE TEXT-ONLY SOCIAL READBACK; not published.');
        $this->line('Release: '.$report->releaseRef);
        $this->line('SHA-256: '.$report->artifactDigest);
        $this->line('Social metadata: '.($report->socialMetadataEnabled?'on':'off'));
        return Command::SUCCESS;
    }
)->purpose('Audit fixed offline social metadata against immutable private HTML');


/**
 * F1-219: independent read-only CLI audit of an ALREADY exported fictional
 * private page. All selectors are from a finite catalog; no arbitrary path,
 * public URL, tenant identifier or real SEO provider can be passed in.
 */
Artisan::command(
    'sites:inspect-seo-fixture {product=onepage} {preset=classic} {order=services_process_faq} {palette=default} {navigation=off} {social=off}',
    function (): int {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F219_SEO_READBACK')!=='1') {
            $this->error('SEO package inspection disabled outside CI-only lab.');
            return Command::FAILURE;
        }

        $product=(string)$this->argument('product');
        $preset=(string)$this->argument('preset');
        $order=(string)$this->argument('order');
        $palette=(string)$this->argument('palette');
        $navigation=(string)$this->argument('navigation');
        $social=(string)$this->argument('social');

        if (!in_array($social,['on','off'],true)) {
            $this->error('Unsupported social metadata mode.');
            return Command::FAILURE;
        }
        try {
            \Imagine\Sites\Production\SyntheticVisualPresetCatalog::choose(
                $product,$preset,$order,$navigation,$palette
            );
        }catch(\InvalidArgumentException){
            $this->error('Unsupported synthetic design selector.');
            return Command::FAILURE;
        }

        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        $directory=storage_path('app/private/sites-f1-205-').
            $product.'-'.$preset.'-'.$order.'-'.$palette.'-'.$navigation.
            ($social==='on'?'-social-on':'');
        try {
            if (!is_file($fixture) || is_link($fixture) ||
                filesize($fixture)>8192 ||
                !is_dir($directory) || is_link($directory)) {
                throw new \DomainException('Missing fixed private SEO fixture.');
            }

            // Two checks: first enforce physical private-file boundary,
            // then cryptographically verify the exact bytes just read.
            // This does not claim atomic file handles against hostile FS.
            $physical=\Imagine\Sites\Production\SyntheticPrivateDraftVerifier::verifyDirectory(
                $directory
            );
            $files=[];
            foreach([
                'index.html','internal-review-dossier.json',
                'internal-review-draft.json','release-manifest.json',
            ] as $filename) {
                $bytes=file_get_contents($directory.'/'.$filename);
                if (!is_string($bytes)) {
                    throw new \DomainException('Incomplete private SEO file readback.');
                }
                $files[$filename]=$bytes;
            }

            $copy=json_decode((string)file_get_contents($fixture),
                true,16,JSON_THROW_ON_ERROR);
            $review=\Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture::build(
                $copy,$product,$preset,$order,$navigation,$palette,$social==='on'
            );
            $parity=\Imagine\Sites\Production\SyntheticVisualReviewExportParityV1::inspect(
                $review,$files
            );
            if (!hash_equals($parity->artifactDigest,$physical['artifactDigest']) ||
                !hash_equals($parity->artifactDigest,$review->draft->artifact->digest) ||
                $parity->releaseRef!==$physical['releaseRef'] ||
                $parity->releaseRef!==$review->draft->manifest->releaseRef ||
                $parity->socialEnabled!==($social==='on') ||
                $parity->canPublish() || $parity->canIndexOnSearchEngines() ||
                $parity->canAcceptForClient() || $parity->canWriteCrm() ||
                $parity->canSkipReview() ||
                $parity->seoSnippet->canIndexOnSearchEngines()) {
                throw new \DomainException('Private SEO inspection authority or digest mismatch.');
            }
        }catch(\Throwable){
            $this->error('Private SEO readback denied. Nothing was published.');
            return Command::FAILURE;
        }

        $this->line('F1-219 VERIFIED PRIVATE SEO TITLE/DESCRIPTION; noindex and pending.');
        $this->line('Release: '.$parity->releaseRef);
        $this->line('SHA-256: '.$parity->artifactDigest);
        $this->line('SEO title characters: '.$parity->seoSnippet->titleCharacters);
        $this->line('SEO description characters: '.$parity->seoSnippet->descriptionCharacters);
        $this->line('SEO advisories: '.(
            $parity->seoSnippet->advisories===[]
            ? 'none'
            : implode(',',$parity->seoSnippet->advisories)
        ));
        $this->line('Social metadata: '.$social.'; no public URL or index permission.');
        return Command::SUCCESS;
    }
)->purpose('Audit an existing fixed private SEO preview without network or writes');


/**
 * F1-223: export exactly one fixed, non-public SEO copy review variant.
 * Does not accept a path, text, real client, URL or approval from arguments.
 */
Artisan::command(
    'sites:export-seo-copy-fixture {product=onepage} {preset=classic} {seo=default} {social=off}',
    function (): int {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F223_SEO_EXPORT')!=='1') {
            $this->error('SEO copy export disabled outside synthetic CI.');
            return Command::FAILURE;
        }
        $product=(string)$this->argument('product');
        $preset=(string)$this->argument('preset');
        $seoMode=(string)$this->argument('seo');
        $socialMode=(string)$this->argument('social');
        if (!in_array($product,['onepage','landing_page'],true) ||
            !in_array($preset,['classic','modern','warm'],true) ||
            !in_array($seoMode,['default','custom'],true) ||
            !in_array($socialMode,['off','on'],true)) {
            $this->error('Only fixed fictional SEO copy combinations are supported.');
            return Command::FAILURE;
        }
        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        $root=storage_path('app/private');
        $base='sites-f1-223-'.$product.'-'.$preset.'-'.$seoMode.'-'.$socialMode;
        $destination=$root.'/'.$base;
        if (!is_file($fixture) || is_link($fixture) ||
            filesize($fixture)>8192 ||
            is_link($root) || is_link($destination) ||
            file_exists($destination)) {
            $this->error('Synthetic SEO fixture or private destination is unsafe.');
            return Command::FAILURE;
        }
        try {
            $copy=json_decode((string)file_get_contents($fixture),
                true,16,JSON_THROW_ON_ERROR);
            if (!is_array($copy)) {
                throw new \DomainException('Invalid fixed SEO copy source.');
            }
            $candidate=\Imagine\Sites\Production\SyntheticSeoCopyFixtureV1::build(
                $copy,$product,$preset,$seoMode,$socialMode
            );
            if ($candidate->canPublish() || $candidate->canIndex() ||
                $candidate->canSendToSeoEngine() ||
                $candidate->canApproveForClient()) {
                throw new \DomainException('Synthetic SEO candidate implies real authority.');
            }
            if (!is_dir($root) &&
                !mkdir($root,0700,true) && !is_dir($root)) {
                throw new \DomainException('Missing private CI root.');
            }
            if (is_link($root) || !is_dir($root)) {
                throw new \DomainException('Unsafe private CI root.');
            }
            $proof=\Imagine\Sites\Production\SyntheticPrivateDraftWriter::create(
                $destination,$candidate->files
            );
            if ($proof['releaseRef']!==$candidate->draft->manifest->releaseRef ||
                !hash_equals(
                    $proof['artifactDigest'],$candidate->draft->artifact->digest
                ) ||
                $proof['reviewerDecisionStatus']!=='pending' ||
                $proof['officialClientAcceptance']!==false ||
                $proof['publishable']!==false) {
                throw new \DomainException('Private SEO readback cannot be accepted.');
            }
        } catch (\Throwable) {
            $this->error('Fixed SEO export denied. Nothing was published or indexed.');
            return Command::FAILURE;
        }
        $this->line('F1-223 PRIVATE SEO COPY: pending, noindex, no client approval.');
        $this->line('Release: '.$candidate->draft->manifest->releaseRef);
        $this->line('SHA-256: '.$proof['artifactDigest']);
        $this->line('SEO title characters: '.$candidate->readback->snippet->titleCharacters);
        $this->line('SEO description characters: '.$candidate->readback->snippet->descriptionCharacters);
        return Command::SUCCESS;
    }
)->purpose('Create a fictional pending-only SEO copy review package in private CI');


/**
 * F1-225: inspect EXISTING SEO-copy private review package without writes.
 * The four selectors and directory are fixed: no URL, file path, text,
 * personal data, service account or published release is accepted.
 */
Artisan::command(
    'sites:audit-seo-copy-fixture {product=onepage} {preset=classic} {seo=default} {social=off}',
    function (): int {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F225_SEO_AUDIT')!=='1') {
            $this->error('SEO copy readback disabled outside isolated CI.');
            return Command::FAILURE;
        }
        $product=(string)$this->argument('product');
        $preset=(string)$this->argument('preset');
        $seo=(string)$this->argument('seo');
        $social=(string)$this->argument('social');
        if (!in_array($product,['onepage','landing_page'],true) ||
            !in_array($preset,['classic','modern','warm'],true) ||
            !in_array($seo,['default','custom'],true) ||
            !in_array($social,['off','on'],true)) {
            $this->error('Only a known fixed SEO review can be audited.');
            return Command::FAILURE;
        }

        $source=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        $root=storage_path('app/private');
        $directory=$root.'/sites-f1-223-'.$product.'-'.$preset.'-'.$seo.'-'.$social;
        try {
            if (!is_file($source) || is_link($source) ||
                filesize($source)>8192 || is_link($root) ||
                !is_dir($directory) || is_link($directory)) {
                throw new \DomainException('Private SEO copy fixture is missing or unsafe.');
            }
            $physicalBefore=\Imagine\Sites\Production\SyntheticPrivateDraftVerifier::verifyDirectory(
                $directory
            );
            $files=[];
            foreach([
                'index.html','internal-review-dossier.json',
                'internal-review-draft.json','release-manifest.json'
            ] as $name) {
                if (!is_file($directory.'/'.$name) ||
                    is_link($directory.'/'.$name)) {
                    throw new \DomainException('Private SEO file missing or linked.');
                }
                $bytes=file_get_contents($directory.'/'.$name);
                if (!is_string($bytes)) {
                    throw new \DomainException('Private SEO file was unreadable.');
                }
                $files[$name]=$bytes;
            }
            $fixture=json_decode((string)file_get_contents($source),
                true,16,JSON_THROW_ON_ERROR);
            if (!is_array($fixture)) {
                throw new \DomainException('Unexpected fixed SEO candidate source.');
            }
            $expected=\Imagine\Sites\Production\SyntheticSeoCopyFixtureV1::build(
                $fixture,$product,$preset,$seo,$social
            );
            $readback=\Imagine\Sites\Production\SyntheticPrivateSeoSnippetReadbackV1::inspect(
                $files,$expected->draft->brief
            );
            // Recheck directory permissions/integrity after reading individual
            // files. This is not an atomic snapshot of an untrusted filesystem.
            $physicalAfter=\Imagine\Sites\Production\SyntheticPrivateDraftVerifier::verifyDirectory(
                $directory
            );
            if ($physicalBefore!==$physicalAfter ||
                $physicalAfter['releaseRef']!==$expected->draft->manifest->releaseRef ||
                $readback->releaseRef!==$physicalAfter['releaseRef'] ||
                !hash_equals(
                    $physicalAfter['artifactDigest'],$readback->artifactDigest
                ) ||
                !hash_equals(
                    $readback->artifactDigest,$expected->draft->artifact->digest
                ) ||
                $physicalAfter['reviewerDecisionStatus']!=='pending' ||
                $physicalAfter['publishable']!==false ||
                $physicalAfter['officialClientAcceptance']!==false ||
                $readback->canPublish() || $readback->canIndex() ||
                $readback->canSendToSeoEngine() ||
                $readback->canApproveForClient()) {
                throw new \DomainException('Private SEO audit proof mismatch.');
            }
        }catch(\Throwable) {
            $this->error('Private SEO copy audit denied; no index or publish action.');
            return Command::FAILURE;
        }

        // Only static identifiers, byte digest and numeric lengths:
        // never echo the copy or a customer-controlled URL.
        $this->line('F1-225 PRIVATE SEO COPY VERIFIED: pending, noindex, no client approval.');
        $this->line('Release: '.$readback->releaseRef);
        $this->line('SHA-256: '.$readback->artifactDigest);
        $this->line('SEO title characters: '.$readback->snippet->titleCharacters);
        $this->line('SEO description characters: '.$readback->snippet->descriptionCharacters);
        $this->line('Advisories: '.(
            $readback->snippet->advisories===[]
            ? 'none':implode(',',$readback->snippet->advisories)
        ));
        return Command::SUCCESS;
    }
)->purpose('Audit a previously exported, nonpublic SEO-copy review without changes');


/**
 * F1-227: read-only comparison of two ALREADY exported SEO copy packages.
 * Never creates/overwrites a file or sends metadata to public SEO services.
 */
Artisan::command(
    'sites:diff-seo-copy-fixture {product=onepage} {preset=classic} {social=off} {--evidence-json}',
    function (): int {
        if (PHP_SAPI!=='cli' || !app()->environment('testing') ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F227_SEO_DIFF')!=='1') {
            $this->error('SEO copy comparison denied outside fixed CI laboratory.');
            return Command::FAILURE;
        }
        $product=(string)$this->argument('product');
        $preset=(string)$this->argument('preset');
        $social=(string)$this->argument('social');
        if (!in_array($product,['onepage','landing_page'],true) ||
            !in_array($preset,['classic','modern','warm'],true) ||
            !in_array($social,['off','on'],true)) {
            $this->error('Unsupported fixed comparison selectors.');
            return Command::FAILURE;
        }

        $fixture=base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json');
        $root=storage_path('app/private');
        try {
            if (!is_file($fixture) || is_link($fixture) ||
                filesize($fixture)>8192 || !is_dir($root) || is_link($root)) {
                throw new \DomainException('Expected private SEO fixture not present.');
            }
            $copy=json_decode((string)file_get_contents($fixture),
                true,16,JSON_THROW_ON_ERROR);
            if (!is_array($copy)) {
                throw new \DomainException('Fixed SEO copy malformed.');
            }
            $files=[];
            $briefs=[];
            $physical=[];
            foreach(['default','custom'] as $mode) {
                $dir=$root.'/sites-f1-223-'.$product.'-'.$preset.'-'.$mode.'-'.$social;
                if (!is_dir($dir) || is_link($dir)) {
                    throw new \DomainException('Missing private SEO comparison half.');
                }
                $physical[$mode]=\Imagine\Sites\Production\SyntheticPrivateDraftVerifier::verifyDirectory(
                    $dir
                );
                $files[$mode]=[];
                foreach([
                    'index.html','internal-review-dossier.json',
                    'internal-review-draft.json','release-manifest.json'
                ] as $name) {
                    if (!is_file($dir.'/'.$name) || is_link($dir.'/'.$name)) {
                        throw new \DomainException('Private comparison file unsafe.');
                    }
                    $bytes=file_get_contents($dir.'/'.$name);
                    if (!is_string($bytes)) {
                        throw new \DomainException('Failed to read a private comparison file.');
                    }
                    $files[$mode][$name]=$bytes;
                }
                $expected=\Imagine\Sites\Production\SyntheticSeoCopyFixtureV1::build(
                    $copy,$product,$preset,$mode,$social
                );
                $briefs[$mode]=$expected->draft->brief;
                if ($physical[$mode]['tenantRef']!==$expected->draft->manifest->tenantRef ||
                    $physical[$mode]['siteRef']!==$expected->draft->manifest->siteRef ||
                    $physical[$mode]['releaseRef']!==
                    $expected->draft->manifest->releaseRef ||
                    !hash_equals(
                        $physical[$mode]['artifactDigest'],
                        $expected->draft->artifact->digest
                    )) {
                    throw new \DomainException('Compared private SEO release not expected.');
                }
                $second=\Imagine\Sites\Production\SyntheticPrivateDraftVerifier::verifyDirectory($dir);
                if ($second!==$physical[$mode]) {
                    throw new \DomainException('Private comparison changed while reading.');
                }
            }
            $diff=\Imagine\Sites\Production\SyntheticPrivateSeoCopyDiffV1::inspect(
                $files['default'],$briefs['default'],
                $files['custom'],$briefs['custom']
            );
            if ($diff->changedFields!==['seoTitle','seoDescription'] ||
                $diff->socialEnabled!==($social==='on') ||
                !hash_equals($diff->defaultDigest,$physical['default']['artifactDigest']) ||
                !hash_equals($diff->customDigest,$physical['custom']['artifactDigest']) ||
                $diff->canPublish() || $diff->canIndex() ||
                $diff->canApproveForClient() || $diff->canWriteSeoEngine()) {
                throw new \DomainException('SEO diff could claim unauthorized publication.');
            }
            // F1-229: revalidate the same two packages and pin each of the
            // eight private files. JSON is evidence, never authorization.
            $evidence=\Imagine\Sites\Production\SyntheticPrivateSeoChangeEvidenceV1::fromVerifiedPair(
                $files['default'],$briefs['default'],
                $files['custom'],$briefs['custom']
            );
            if (!$evidence->stillMatchesVerifiedPair(
                    $files['default'],$briefs['default'],
                    $files['custom'],$briefs['custom']
                ) ||
                $evidence->record['authority']!=='none' ||
                $evidence->record['publishable']!==false ||
                $evidence->record['clientAccepted']!==false ||
                $evidence->record['searchIndexingAllowed']!==false ||
                $evidence->canPublish() || $evidence->canIndex() ||
                $evidence->canApproveForClient() || $evidence->canWriteSeoEngine()) {
                throw new \DomainException('Private SEO evidence never grants authority.');
            }
        } catch (\Throwable) {
            $this->error('Private SEO copy comparison denied; no files or index changed.');
            return Command::FAILURE;
        }
        if ((bool)$this->option('evidence-json')) {
            // JSON-only stdout for controlled, read-only CI review; never a
            // signed consent or an outbound API payload.
            $this->line(rtrim($evidence->json,"\n"));
            return Command::SUCCESS;
        }
        $this->line('F1-227 SEO COPY DIFFERENCE VERIFIED: head only; pending, noindex.');
        $this->line('Default SHA-256: '.$diff->defaultDigest);
        $this->line('Custom SHA-256: '.$diff->customDigest);
        $this->line('Changes: '.implode(',',$diff->changedFields));
        $this->line('F1-229 evidence SHA-256: '.$evidence->digest);
        $this->line('Social metadata: '.$social.'; body unchanged and no publication.');
        return Command::SUCCESS;
    }
)->purpose('Diff two existing fictional SEO-copy review packages without writes');
