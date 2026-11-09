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
