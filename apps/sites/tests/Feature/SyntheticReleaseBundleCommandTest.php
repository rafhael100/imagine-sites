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
