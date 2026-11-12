<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-58: private fixture-only static gallery, without client data or HTTP. */
final class SyntheticStyleGalleryExportTest extends TestCase
{
    private function location():string
    {
        return storage_path('app/private/sites-f1-58-style-gallery');
    }
    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_STYLE_EXPORT=1');
        putenv('SITES_SYNTHETIC_EXPORT');
        parent::setUp();
    }
    protected function tearDown():void
    {
        $dir=$this->location();
        if(is_link($dir))@unlink($dir);
        if(is_dir($dir)){
            foreach(['onepage','landing_page'] as $product) {
                foreach(['desktop','mobile'] as $device) {
                    foreach(['classic','modern','warm'] as $style) {
                        @unlink($dir.'/'.$product.'-'.$device.'-'.$style.'.html');
                    }
                }
            }
            @unlink($dir.'/gallery-manifest.json');
            @rmdir($dir);
        }
        putenv('SITES_SYNTHETIC_STYLE_EXPORT');
        putenv('SITES_SYNTHETIC_HTTP');
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        putenv('SITES_SYNTHETIC_EXPORT');
        parent::tearDown();
    }

    public function test_twelve_exact_previews_with_local_navigation_and_inert_digests():void
    {
        self::assertSame(0,Artisan::call('sites:export-style-gallery-fixture'));
        $root=$this->location();
        $manifest=json_decode((string)file_get_contents($root.'/gallery-manifest.json'),
            true,16,JSON_THROW_ON_ERROR);
        self::assertSame('sites.synthetic.style-gallery.v1',$manifest['schemaVersion']);
        self::assertTrue($manifest['syntheticOnly']);
        self::assertFalse($manifest['publishable']);
        self::assertFalse($manifest['officialClientAcceptance']);
        self::assertCount(12,$manifest['pages']);
        $seen=[];$byProductTheme=[];
        foreach($manifest['pages'] as $page){
            $filename=$page['filename'];
            self::assertMatchesRegularExpression(
                '/\A(?:onepage|landing_page)-(?:desktop|mobile)-(?:classic|modern|warm)\.html\z/',
                $filename
            );
            self::assertArrayNotHasKey($filename,$seen);
            $seen[$filename]=true;
            $body=(string)file_get_contents($root.'/'.$filename);
            self::assertSame(strlen($body),$page['bytes']);
            self::assertSame(hash('sha256',$body),$page['sha256']);
            self::assertSame(7,substr_count($body,'href="onepage-')+
                substr_count($body,'href="landing_page-'));
            self::assertStringNotContainsString('href="/_lab/sites/v1/review?',$body);
            self::assertStringContainsString('http-equiv="Content-Security-Policy"',$body);
            self::assertStringContainsString('name="robots" content="noindex, nofollow"',$body);
            self::assertStringContainsString('tabindex="0"',$body);
            self::assertStringContainsString('sandbox=""',$body);
            self::assertStringContainsString('Aceite oficial pendente no Portal',$body);
            self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$body);
            self::assertStringNotContainsString('<script',$body);
            self::assertStringNotContainsString('<form',$body);
            self::assertStringNotContainsString('https://',$body);
            self::assertStringNotContainsString('http://',$body);
            self::assertStringContainsString('class="browser '.$page['device'].'"',$body);
            self::assertStringContainsString('release-f38-'.$page['product'].
                ($page['preset']==='classic'?'':'-'.$page['preset']),$body);
            self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$body,$m));
            $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            self::assertSame($page['artifactDigest'],hash('sha256',$inner));
            self::assertStringContainsString('data-site-product="'.
                ($page['product']==='onepage'?'onepage':'landing-page').'"',$inner);
            if($page['preset']==='classic'){
                self::assertStringNotContainsString('visual-preset: ',$inner);
            } else {
                self::assertStringContainsString('visual-preset: '.$page['preset'],$inner);
            }
            foreach(['onepage','landing_page'] as $product) {
                self::assertStringContainsString('href="'.$product.'-'.$page['device'].'-'.
                    $page['preset'].'.html"',$body);
            }
            foreach(['desktop','mobile'] as $device) {
                self::assertStringContainsString('href="'.$page['product'].'-'.$device.'-'.
                    $page['preset'].'.html"',$body);
            }
            foreach(['classic','modern','warm'] as $preset) {
                self::assertStringContainsString('href="'.$page['product'].'-'.$page['device'].'-'.
                    $preset.'.html"',$body);
            }
            $byProductTheme[$page['product']][$page['preset']]=$page['artifactDigest'];
        }
        self::assertCount(12,$seen);
        foreach(['onepage','landing_page'] as $product) {
            self::assertCount(3,array_unique($byProductTheme[$product]));
        }
    }

    public function test_disabled_without_dedicated_three_flags_and_with_legacy_mode():void
    {
        putenv('SITES_SYNTHETIC_STYLE_EXPORT');
        self::assertSame(1,Artisan::call('sites:export-style-gallery-fixture'));
        putenv('SITES_SYNTHETIC_STYLE_EXPORT=1');
        putenv('SITES_SYNTHETIC_EXPORT=1');
        self::assertSame(1,Artisan::call('sites:export-style-gallery-fixture'));
        putenv('SITES_SYNTHETIC_EXPORT');
        self::assertDirectoryDoesNotExist($this->location());
    }

    public function test_fresh_directory_required_and_no_overwrite():void
    {
        self::assertSame(0,Artisan::call('sites:export-style-gallery-fixture'));
        $file=$this->location().'/gallery-manifest.json';
        $original=(string)file_get_contents($file);
        self::assertSame(1,Artisan::call('sites:export-style-gallery-fixture'));
        self::assertSame($original,(string)file_get_contents($file));
    }

    public function test_symlink_destination_fails_before_writing():void
    {
        $target=storage_path('app/private/sites-f1-58-do-not-change.marker');
        file_put_contents($target,'UNALTERED');
        self::assertTrue(symlink($target,$this->location()));
        try {
            self::assertSame(1,Artisan::call('sites:export-style-gallery-fixture'));
            self::assertSame('UNALTERED',(string)file_get_contents($target));
        } finally {
            @unlink($this->location());
            @unlink($target);
        }
    }
}
