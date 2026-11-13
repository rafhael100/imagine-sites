<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\InertPreviewAudit;
use Tests\TestCase;

/** F1-53: Laravel CLI makes style a whitelisted release dimension. */
final class SyntheticStyleReviewCommandTest extends TestCase
{
    private function outputDir(string $product,string $theme):string
    {
        return storage_path('app/private/sites-f1-37-'.$product.
            ($theme==='classic'?'':'-'.$theme));
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $product){
            foreach(['classic','modern','warm'] as $theme){
                $path=$this->outputDir($product,$theme);
                if(is_link($path)){@unlink($path);continue;}
                if(is_dir($path)){
                    foreach(['index.html','release-manifest.json',
                        'portal-review-candidate.json'] as $name)@unlink($path.'/'.$name);
                    @rmdir($path);
                }
            }
        }
        putenv('SITES_SYNTHETIC_REVIEW');
        parent::tearDown();
    }

    public function test_explicit_theme_selection_preserves_release_integrity():void
    {
        putenv('SITES_SYNTHETIC_REVIEW=1');
        foreach(['onepage','landing_page'] as $product){
            $digests=[];
            foreach(['classic','modern','warm'] as $theme){
                self::assertSame(0,Artisan::call('sites:review-fixture',[
                    'product'=>$product,'preset'=>$theme
                ]));
                $path=$this->outputDir($product,$theme);
                $html=(string)file_get_contents($path.'/index.html');
                $manifest=json_decode((string)file_get_contents(
                    $path.'/release-manifest.json'),true,16,JSON_THROW_ON_ERROR);
                $candidate=json_decode((string)file_get_contents(
                    $path.'/portal-review-candidate.json'),true,16,JSON_THROW_ON_ERROR);
                self::assertTrue(InertPreviewAudit::isSafe($html));
                self::assertSame(hash('sha256',$html),$manifest['artifactDigest']);
                self::assertSame($manifest['artifactDigest'],$candidate['artifactDigest']);
                self::assertSame('release-f37-'.$product.
                    ($theme==='classic'?'':'-'.$theme),$candidate['releaseRef']);
                self::assertFalse($manifest['publishable']);
                self::assertFalse($candidate['publishable']);
                self::assertFalse($candidate['officialClientAcceptance']);
                self::assertTrue($candidate['portalAuthorityRequired']);
                self::assertStringNotContainsString('<script',$html);
                self::assertStringNotContainsString('<form',$html);
                self::assertStringContainsString('noindex, nofollow',$html);
                if($theme!=='classic'){
                    self::assertStringContainsString('visual-preset: '.$theme,$html);
                }
                $digests[]=$manifest['artifactDigest'];
            }
            self::assertCount(3,array_unique($digests));
        }
    }

    public function test_old_default_command_remains_identical_to_explicit_classic():void
    {
        putenv('SITES_SYNTHETIC_REVIEW=1');
        self::assertSame(0,Artisan::call('sites:review-fixture',['product'=>'onepage']));
        $dir=$this->outputDir('onepage','classic');
        $initial=(string)file_get_contents($dir.'/index.html');
        $info=json_decode((string)file_get_contents(
            $dir.'/portal-review-candidate.json'),true,16,JSON_THROW_ON_ERROR);
        self::assertSame('release-f37-onepage',$info['releaseRef']);
        foreach(['index.html','release-manifest.json',
            'portal-review-candidate.json'] as $name)@unlink($dir.'/'.$name);
        @rmdir($dir);
        self::assertSame(0,Artisan::call('sites:review-fixture',[
            'product'=>'onepage','preset'=>'classic'
        ]));
        self::assertSame($initial,(string)file_get_contents($dir.'/index.html'));
    }

    public function test_invalid_theme_and_existing_target_fail_closed():void
    {
        putenv('SITES_SYNTHETIC_REVIEW=1');
        foreach(['','../../secret','<script>','premium','MODERN'] as $theme){
            self::assertSame(1,Artisan::call('sites:review-fixture',[
                'product'=>'onepage','preset'=>$theme
            ]));
        }
        self::assertSame(0,Artisan::call('sites:review-fixture',[
            'product'=>'onepage','preset'=>'warm'
        ]));
        $path=$this->outputDir('onepage','warm');
        $original=(string)file_get_contents($path.'/index.html');
        self::assertSame(1,Artisan::call('sites:review-fixture',[
            'product'=>'onepage','preset'=>'warm'
        ]));
        self::assertSame($original,(string)file_get_contents($path.'/index.html'));
    }

    public function test_command_denies_without_explicit_cli_test_switch():void
    {
        putenv('SITES_SYNTHETIC_REVIEW');
        self::assertSame(1,Artisan::call('sites:review-fixture',[
            'product'=>'onepage','preset'=>'modern'
        ]));
        self::assertDirectoryDoesNotExist($this->outputDir('onepage','modern'));
    }
}
