<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SyntheticReleaseBundleCommandTest extends TestCase
{
    private function path(string $product): string
    {
        return storage_path('app/private/sites-f1-24-'.$product);
    }

    protected function tearDown(): void
    {
        foreach (['onepage','landing_page'] as $product) {
            $dir=$this->path($product);
            if (is_link($dir)) {
                unlink($dir);
                continue;
            }
            if (is_dir($dir)) {
                foreach (['index.html','release-manifest.json'] as $filename) {
                    @unlink($dir.'/'.$filename);
                }
                @rmdir($dir);
            }
        }
        putenv('SITES_SYNTHETIC_BUNDLE');
        putenv('SITES_SYNTHETIC_VERIFY');
        parent::tearDown();
    }

    public function test_denied_without_explicit_lab_flag(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE');
        self::assertSame(1,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        self::assertDirectoryDoesNotExist($this->path('onepage'));
    }

    public function test_private_bundle_contains_exact_rendered_bytes_and_never_publishable_metadata(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        $dir=$this->path('onepage');
        self::assertFileExists($dir.'/index.html');
        self::assertFileExists($dir.'/release-manifest.json');
        $html=(string)file_get_contents($dir.'/index.html');
        $meta=json_decode((string)file_get_contents($dir.'/release-manifest.json'),true,16,JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256',$html),$meta['artifactDigest']);
        self::assertSame(strlen($html),$meta['artifactSizeBytes']);
        self::assertFalse($meta['publishable']);
        self::assertFalse($meta['officialClientAcceptance']);
        self::assertStringContainsString('noindex, nofollow',$html);
        self::assertStringContainsString('PRIVATE SYNTHETIC TEST PACKAGE ONLY',Artisan::output());
        $release=new \Imagine\Sites\Domain\ReleaseManifest(
            'tenant-fixture','site-fixture','release-bundle-onepage',$meta['artifactDigest']
        );
        $files=[
            'index.html'=>$html,
            'release-manifest.json'=>(string)file_get_contents($dir.'/release-manifest.json'),
        ];
        $pinnedDigest=hash('sha256',$files['index.html']."\0".$files['release-manifest.json']);
        self::assertTrue(\Imagine\Sites\Production\SyntheticReleaseBundle::verifyExactBundle(
            $files,$release,$pinnedDigest
        ));
        $files['release-manifest.json'].="\n";
        self::assertFalse(\Imagine\Sites\Production\SyntheticReleaseBundle::verifyExactBundle(
            $files,$release,$pinnedDigest
        ));
    }

    public function test_second_write_cannot_replace_previously_verified_bundle(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        $directory=$this->path('onepage');
        $before=[
            hash_file('sha256',$directory.'/index.html'),
            hash_file('sha256',$directory.'/release-manifest.json'),
        ];
        self::assertSame(1,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        self::assertSame($before,[
            hash_file('sha256',$directory.'/index.html'),
            hash_file('sha256',$directory.'/release-manifest.json'),
        ], 'Preexisting validated package must never be overwritten');
        $leftovers=glob(storage_path('app/private/.sites-f1-24-onepage-*.tmp'));
        self::assertSame([],$leftovers===false ? [] : $leftovers,
            'Temporary staging paths must not be left after successful promotion');
    }

    public function test_read_only_verifier_requires_both_flags_and_two_exact_pins(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        $dir=$this->path('onepage');
        $original=[
            'index.html'=>(string)file_get_contents($dir.'/index.html'),
            'release-manifest.json'=>(string)file_get_contents($dir.'/release-manifest.json'),
        ];
        $artifactDigest=hash('sha256',$original['index.html']);
        $bundleDigest=hash('sha256',$original['index.html']."\0".
            $original['release-manifest.json']);
        $args=[
            'product'=>'onepage',
            'artifactDigest'=>$artifactDigest,
            'bundleDigest'=>$bundleDigest,
        ];

        putenv('SITES_SYNTHETIC_VERIFY');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
            'Readback is never enabled merely by permitting package creation');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$args),
            'Pinned original package must pass read-only verification');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['artifactDigest'=>str_repeat('0',64)])),
            'Wrong independently pinned HTML digest must fail');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['bundleDigest'=>str_repeat('0',64)])),
            'Wrong independently pinned full bundle digest must fail');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['product'=>'landing_page'])),
            'Another product cannot claim the same pinned package');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['product'=>'../onepage'])),
            'Path-like product arguments are never used as filenames');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['bundleDigest'=>strtoupper($bundleDigest)])),
            'Noncanonical pin representations fail closed');

        self::assertSame($original['index.html'],
            file_get_contents($dir.'/index.html'));
        self::assertSame($original['release-manifest.json'],
            file_get_contents($dir.'/release-manifest.json'),
            'Verification itself must never rewrite the bundle');
    }

    public function test_read_only_verifier_detects_mutated_or_extra_private_files(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'landing_page']));
        $dir=$this->path('landing_page');
        $html=(string)file_get_contents($dir.'/index.html');
        $manifest=(string)file_get_contents($dir.'/release-manifest.json');
        $args=[
            'product'=>'landing_page',
            'artifactDigest'=>hash('sha256',$html),
            'bundleDigest'=>hash('sha256',$html."\0".$manifest),
        ];
        self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$args));
        $extra=$dir.'/extra.html';
        try {
            file_put_contents($extra,'untrusted');
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
                'Additional disk files invalidate exact package inventory');
        } finally {
            @unlink($extra);
        }
        file_put_contents($dir.'/release-manifest.json',$manifest."\n");
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
            'A modified manifest cannot match the prior pinned digest');
    }

    public function test_landing_is_supported_with_no_executable_forms(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'landing_page']));
        $html=(string)file_get_contents($this->path('landing_page').'/index.html');
        self::assertStringContainsString('data-site-product="landing-page"',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringNotContainsString('<script',$html);
    }

    public function test_unsafe_product_path_refused(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(1,Artisan::call('sites:package-fixture',['product'=>'../../outside']));
        self::assertDirectoryDoesNotExist($this->path('onepage'));
    }
}
