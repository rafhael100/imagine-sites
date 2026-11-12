<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-162: offline, byte-verified rich-review gallery from real Laravel UI. */
final class SyntheticRichReviewGalleryExportTest extends TestCase
{
    private function directory():string
    {
        return storage_path('app/private/sites-f1-162-rich-gallery');
    }

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        putenv('SITES_SYNTHETIC_RICH_EXPORT=1');
        putenv('SITES_SYNTHETIC_EXPORT');
        putenv('SITES_SYNTHETIC_STYLE_EXPORT');
        parent::setUp();
    }

    protected function tearDown():void
    {
        $dir=$this->directory();
        if (is_link($dir)) @unlink($dir);
        if (is_dir($dir)) {
            foreach (['onepage','landing_page'] as $p) {
                foreach (['desktop','mobile'] as $d) {
                    foreach (['classic','modern','warm'] as $s) {
                        @unlink($dir.'/'.$p.'-'.$d.'-'.$s.'.html');
                    }
                }
            }
            @unlink($dir.'/gallery-manifest.json');
            @rmdir($dir);
        }
        foreach ([
            'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_RICH_REVIEW','SITES_SYNTHETIC_RICH_EXPORT',
            'SITES_SYNTHETIC_EXPORT','SITES_SYNTHETIC_STYLE_EXPORT',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_private_gallery_has_twelve_safe_rich_pages_and_manifest():void
    {
        self::assertSame(0,Artisan::call('sites:export-rich-gallery-fixture'));
        $path=$this->directory();
        $raw=(string)file_get_contents($path.'/gallery-manifest.json');
        $manifest=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        self::assertSame('sites.synthetic.rich-review-gallery.v1',$manifest['schemaVersion']);
        self::assertTrue($manifest['syntheticOnly']);
        self::assertFalse($manifest['publishable']);
        self::assertFalse($manifest['officialClientAcceptance']);
        self::assertCount(12,$manifest['pages']);
        $names=[];
        $digests=[];
        foreach ($manifest['pages'] as $entry) {
            $name=$entry['filename'];
            self::assertMatchesRegularExpression(
                '/\A(?:onepage|landing_page)-(?:desktop|mobile)-(?:classic|modern|warm)\.html\z/D',
                $name
            );
            self::assertArrayNotHasKey($name,$names);
            $names[$name]=true;
            $body=(string)file_get_contents($path.'/'.$name);
            self::assertSame($entry['bytes'],strlen($body));
            self::assertSame($entry['sha256'],hash('sha256',$body));
            self::assertStringContainsString('Aceite oficial pendente no Portal',$body);
            self::assertStringContainsString('Conteúdo da página ampliado',$body);
            self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$body);
            self::assertStringContainsString('name="robots" content="noindex, nofollow"',$body);
            self::assertStringContainsString('http-equiv="Content-Security-Policy"',$body);
            self::assertStringContainsString('sandbox=""',$body);
            self::assertStringContainsString('tabindex="0"',$body);
            self::assertStringNotContainsString('<form',$body);
            self::assertStringNotContainsString('<script',$body);
            self::assertStringNotContainsString('href="/_lab/sites/v1/rich-review?',$body);
            self::assertSame(7,substr_count($body,'href="onepage-')+
                substr_count($body,'href="landing_page-'));
            foreach (['onepage','landing_page'] as $p) {
                self::assertStringContainsString('href="'.$p.'-'.$entry['device'].'-'.
                    $entry['preset'].'.html"',$body);
            }
            foreach (['desktop','mobile'] as $d) {
                self::assertStringContainsString('href="'.$entry['product'].'-'.$d.'-'.
                    $entry['preset'].'.html"',$body);
            }
            foreach (['classic','modern','warm'] as $s) {
                self::assertStringContainsString('href="'.$entry['product'].'-'.
                    $entry['device'].'-'.$s.'.html"',$body);
            }
            self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$body,$matches));
            $inner=html_entity_decode($matches[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            self::assertSame($entry['artifactDigest'],hash('sha256',$inner));
            self::assertSame(1,substr_count($inner,'<section class="faq"'));
            self::assertSame(1,substr_count($inner,'<section class="highlights"'));
            self::assertStringNotContainsString('<form',$inner);
            self::assertStringNotContainsString('<script',$inner);
            self::assertStringContainsString('data-site-product="'.
                ($entry['product']==='onepage'?'onepage':'landing-page').'"',$inner);
            $digests[$entry['product']][$entry['preset']][$entry['device']]
                =$entry['artifactDigest'];
        }
        self::assertCount(12,$names);
        foreach ($digests as $byPreset) {
            foreach ($byPreset as $byDevice) {
                self::assertSame($byDevice['desktop'],$byDevice['mobile']);
            }
            self::assertCount(3,array_unique(array_map(
                static fn(array $byDevice):string=>$byDevice['desktop'],$byPreset
            )));
        }
    }

    public function test_export_needs_independent_flags_and_no_legacy_export():void
    {
        foreach ([
            'SITES_SYNTHETIC_RICH_EXPORT',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag) {
            putenv($flag);
            self::assertSame(1,Artisan::call('sites:export-rich-gallery-fixture'));
            putenv($flag.'=1');
        }
        putenv('SITES_SYNTHETIC_EXPORT=1');
        self::assertSame(1,Artisan::call('sites:export-rich-gallery-fixture'));
        putenv('SITES_SYNTHETIC_EXPORT');
        putenv('SITES_SYNTHETIC_STYLE_EXPORT=1');
        self::assertSame(1,Artisan::call('sites:export-rich-gallery-fixture'));
        putenv('SITES_SYNTHETIC_STYLE_EXPORT');
        self::assertDirectoryDoesNotExist($this->directory());
    }

    public function test_repeated_export_refuses_to_replace_existing_gallery():void
    {
        self::assertSame(0,Artisan::call('sites:export-rich-gallery-fixture'));
        $before=(string)file_get_contents($this->directory().'/gallery-manifest.json');
        self::assertSame(1,Artisan::call('sites:export-rich-gallery-fixture'));
        self::assertSame($before,
            (string)file_get_contents($this->directory().'/gallery-manifest.json'));
    }

    public function test_symlink_destination_is_rejected_without_touching_target():void
    {
        $target=storage_path('app/private/f162-existing-protected.marker');
        file_put_contents($target,'UNCHANGED');
        self::assertTrue(symlink($target,$this->directory()));
        try {
            self::assertSame(1,Artisan::call('sites:export-rich-gallery-fixture'));
            self::assertSame('UNCHANGED',(string)file_get_contents($target));
        } finally {
            @unlink($this->directory());
            @unlink($target);
        }
    }
}
