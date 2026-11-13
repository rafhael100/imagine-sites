<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SyntheticReviewGalleryExportTest extends TestCase
{
    private function directory(): string
    {
        return storage_path('app/private/sites-f1-41-gallery');
    }

    protected function setUp(): void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_EXPORT=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $dir=$this->directory();
        if (is_link($dir)) @unlink($dir);
        if (is_dir($dir)) {
            foreach (['onepage-desktop.html','onepage-mobile.html',
                'landing_page-desktop.html','landing_page-mobile.html',
                'gallery-manifest.json'] as $name) {
                @unlink($dir.'/'.$name);
            }
            @rmdir($dir);
        }
        putenv('SITES_SYNTHETIC_EXPORT');
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        putenv('SITES_SYNTHETIC_HTTP');
        parent::tearDown();
    }

    public function test_export_is_denied_without_third_explicit_lab_switch(): void
    {
        putenv('SITES_SYNTHETIC_EXPORT');
        try {
            self::assertSame(1,Artisan::call('sites:export-review-gallery-fixture'));
            self::assertDirectoryDoesNotExist($this->directory());
        } finally {
            putenv('SITES_SYNTHETIC_EXPORT=1');
        }
    }

    public function test_all_four_offline_pages_are_integral_self_contained_and_not_publishable(): void
    {
        self::assertSame(0,Artisan::call('sites:export-review-gallery-fixture'));
        $dir=$this->directory();
        self::assertFileExists($dir.'/gallery-manifest.json');
        $manifest=json_decode((string)file_get_contents($dir.'/gallery-manifest.json'),
            true,16,JSON_THROW_ON_ERROR);
        self::assertSame('sites.synthetic.visual-gallery.v1',$manifest['schemaVersion']);
        self::assertTrue($manifest['syntheticOnly']);
        self::assertFalse($manifest['publishable']);
        self::assertFalse($manifest['officialClientAcceptance']);
        self::assertCount(4,$manifest['pages']);

        foreach ($manifest['pages'] as $meta) {
            $file=$dir.'/'.$meta['filename'];
            self::assertFileExists($file);
            $html=(string)file_get_contents($file);
            self::assertSame(strlen($html),$meta['bytes']);
            self::assertSame(hash('sha256',$html),$meta['sha256']);
            self::assertStringContainsString('noindex, nofollow',$html);
            self::assertStringContainsString('http-equiv="Content-Security-Policy"',$html);
            self::assertStringContainsString('sandbox=""',$html);
            self::assertStringContainsString('href="#apos-previa"',$html);
            self::assertStringContainsString(
                'class="foot" id="apos-previa" tabindex="-1"',$html
            );
            self::assertTrue(
                strpos($html,'href="#apos-previa"') <
                strpos($html,'<iframe ') &&
                strpos($html,'<iframe ') <
                strpos($html,'id="apos-previa"'),
                'Keyboard skip must appear before the sandbox and land after it'
            );
            self::assertStringContainsString('Aceite oficial pendente no Portal',$html);
            self::assertStringNotContainsString('<script',$html);
            self::assertStringNotContainsString('<form',$html);
            self::assertStringNotContainsString('href="/_lab/sites/v1/review?',$html);
            self::assertStringNotContainsString('http://',$html);
            self::assertStringNotContainsString('https://',$html);

            self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$html,$matches));
            $inner=html_entity_decode($matches[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            self::assertStringStartsWith('<!doctype html>',$inner);
            self::assertSame(1,preg_match(
                '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$html,$digest));
            self::assertSame(hash('sha256',$inner),$digest[1]);

            $filename=$meta['filename'];
            self::assertMatchesRegularExpression(
                '/\A(?:onepage|landing_page)-(?:desktop|mobile)\.html\z/',$filename);
            $parts=explode('-',substr($filename,0,-5));
            $product=$parts[0];
            $device=$parts[1];
            self::assertStringContainsString('class="browser '.$device.'"',$html);
            self::assertStringContainsString('data-site-product="'.
                ($product==='onepage'?'onepage':'landing-page').'"',$inner);
            self::assertStringContainsString('href="onepage-'.$device.'.html"',$html);
            self::assertStringContainsString('href="landing_page-'.$device.'.html"',$html);
            self::assertStringContainsString('href="'.$product.'-desktop.html"',$html);
            self::assertStringContainsString('href="'.$product.'-mobile.html"',$html);
        }
    }

    public function test_existing_directory_never_gets_overwritten(): void
    {
        self::assertSame(0,Artisan::call('sites:export-review-gallery-fixture'));
        $manifest=$this->directory().'/gallery-manifest.json';
        $original=(string)file_get_contents($manifest);
        self::assertSame(1,Artisan::call('sites:export-review-gallery-fixture'));
        self::assertSame($original,(string)file_get_contents($manifest));
    }

    public function test_a_symlink_destination_is_denied_without_writing_to_target(): void
    {
        $target=storage_path('app/private/sites-f1-41-unchanged.marker');
        file_put_contents($target,'KEEP-PRIVATE');
        self::assertTrue(symlink($target,$this->directory()));
        try {
            self::assertSame(1,Artisan::call('sites:export-review-gallery-fixture'));
            self::assertSame('KEEP-PRIVATE',(string)file_get_contents($target));
        } finally {
            @unlink($this->directory());
            @unlink($target);
        }
    }
}
