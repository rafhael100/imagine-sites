<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\InertPreviewAudit;
use Tests\TestCase;

/** F1-72: offline build of synthetic, non-approved private draft artifacts. */
final class SyntheticStructuredDraftCommandTest extends TestCase
{
    private function output(string $product,string $preset,string $variant):string
    {
        return storage_path('app/private/sites-f1-72-'.$product.'-'.$preset.'-'.$variant);
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['basic','focused'] as $variant) {
                    $dir=$this->output($product,$preset,$variant);
                    if (is_link($dir)) { @unlink($dir); continue; }
                    if (!is_dir($dir)) continue;
                    foreach(['index.html','release-manifest.json','internal-review-draft.json'] as $name) {
                        @unlink($dir.'/'.$name);
                    }
                    @rmdir($dir);
                }
            }
        }
        putenv('SITES_SYNTHETIC_DRAFT');
        parent::tearDown();
    }

    public function test_all_twelve_draft_variants_are_private_inert_and_unapproved():void
    {
        putenv('SITES_SYNTHETIC_DRAFT=1');
        $digests=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['basic','focused'] as $variant) {
                    self::assertSame(0,Artisan::call('sites:assemble-draft-fixture',[
                        'product'=>$product,'preset'=>$preset,'variant'=>$variant,
                    ]));
                    $dir=$this->output($product,$preset,$variant);
                    self::assertDirectoryExists($dir);
                    $files=scandir($dir);
                    self::assertSame([
                        '.','..','index.html','internal-review-draft.json','release-manifest.json',
                    ],$files);
                    $html=(string)file_get_contents($dir.'/index.html');
                    $manifest=json_decode((string)file_get_contents(
                        $dir.'/release-manifest.json'),true,16,JSON_THROW_ON_ERROR);
                    $summary=json_decode((string)file_get_contents(
                        $dir.'/internal-review-draft.json'),true,16,JSON_THROW_ON_ERROR);
                    $digest=hash('sha256',$html);
                    self::assertTrue(InertPreviewAudit::isSafe($html));
                    self::assertSame($digest,$manifest['artifactDigest']);
                    self::assertSame($digest,$summary['artifactDigest']);
                    self::assertSame(strlen($html),$manifest['artifactSizeBytes']);
                    self::assertSame('internal_review',$summary['workflowState']);
                    self::assertSame(2,$summary['workflowRevision']);
                    self::assertTrue($summary['internalReviewRequired']);
                    self::assertTrue($summary['portalAuthorityRequired']);
                    self::assertFalse($summary['officialClientAcceptance']);
                    self::assertFalse($summary['publishable']);
                    self::assertFalse($manifest['officialClientAcceptance']);
                    self::assertFalse($manifest['publishable']);
                    self::assertSame('release-f72-'.$product.'-'.$preset.'-'.$variant,
                        $summary['releaseRef']);
                    self::assertSame($manifest['releaseRef'],$summary['releaseRef']);
                    self::assertStringContainsString('NOT DELIVERED',Artisan::output());
                    self::assertStringNotContainsString('https://',$html);
                    self::assertStringNotContainsString('<form',$html);
                    $digests[]=$digest;
                }
            }
        }
        self::assertCount(12,array_unique($digests));
    }

    public function test_default_and_invalid_options_fail_closed():void
    {
        putenv('SITES_SYNTHETIC_DRAFT');
        self::assertSame(1,Artisan::call('sites:assemble-draft-fixture'));
        self::assertDirectoryDoesNotExist($this->output('onepage','classic','basic'));
        putenv('SITES_SYNTHETIC_DRAFT=1');
        foreach([
            ['product'=>'../../escaped','preset'=>'classic','variant'=>'basic'],
            ['product'=>'onepage','preset'=>'remote','variant'=>'basic'],
            ['product'=>'onepage','preset'=>'classic','variant'=>'../bad'],
        ] as $args) {
            self::assertSame(1,Artisan::call('sites:assemble-draft-fixture',$args));
        }
        self::assertSame(0,Artisan::call('sites:assemble-draft-fixture',[
            'product'=>'onepage','preset'=>'classic','variant'=>'basic',
        ]));
        $path=$this->output('onepage','classic','basic').'/index.html';
        $before=hash_file('sha256',$path);
        self::assertSame(1,Artisan::call('sites:assemble-draft-fixture',[
            'product'=>'onepage','preset'=>'classic','variant'=>'basic',
        ]));
        self::assertSame($before,hash_file('sha256',$path));
    }

    public function test_symlink_output_is_never_followed():void
    {
        putenv('SITES_SYNTHETIC_DRAFT=1');
        $path=$this->output('landing_page','warm','focused');
        $marker=storage_path('app/private/sites-f72-marker.txt');
        file_put_contents($marker,'UNCHANGED');
        self::assertTrue(symlink($marker,$path));
        try {
            self::assertSame(1,Artisan::call('sites:assemble-draft-fixture',[
                'product'=>'landing_page','preset'=>'warm','variant'=>'focused',
            ]));
            self::assertSame('UNCHANGED',(string)file_get_contents($marker));
        } finally {
            @unlink($path);
            @unlink($marker);
        }
    }
}
