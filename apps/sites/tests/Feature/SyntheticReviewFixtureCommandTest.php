<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\InertPreviewAudit;
use Tests\TestCase;

final class SyntheticReviewFixtureCommandTest extends TestCase
{
    private function fixtureDir(string $product): string
    {
        return storage_path('app/private/sites-f1-37-'.$product);
    }

    protected function tearDown(): void
    {
        foreach (['onepage','landing_page'] as $product) {
            $dir=$this->fixtureDir($product);
            if (is_link($dir)) {
                @unlink($dir);
                continue;
            }
            if (is_dir($dir)) {
                foreach (['index.html','release-manifest.json','portal-review-candidate.json']
                    as $filename) {
                    @unlink($dir.'/'.$filename);
                }
                @rmdir($dir);
            }
        }
        putenv('SITES_SYNTHETIC_REVIEW');
        parent::tearDown();
    }

    public function test_default_is_denied_without_explicit_test_flag(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW');
        self::assertSame(1,Artisan::call('sites:review-fixture',['product'=>'onepage']));
        self::assertDirectoryDoesNotExist($this->fixtureDir('onepage'));
    }

    public function test_onepage_produces_private_inert_preview_and_portal_review_proposal_only(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW=1');
        self::assertSame(0,Artisan::call('sites:review-fixture',['product'=>'onepage']));
        $dir=$this->fixtureDir('onepage');
        $html=(string)file_get_contents($dir.'/index.html');
        $meta=json_decode((string)file_get_contents(
            $dir.'/release-manifest.json'),true,16,JSON_THROW_ON_ERROR);
        $candidate=json_decode((string)file_get_contents(
            $dir.'/portal-review-candidate.json'),true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(InertPreviewAudit::isSafe($html));
        self::assertSame(hash('sha256',$html),$meta['artifactDigest']);
        self::assertSame($meta['artifactDigest'],$candidate['artifactDigest']);
        self::assertSame('release-f37-onepage',$candidate['releaseRef']);
        self::assertSame(3,$candidate['workflowRevision']);
        self::assertFalse($candidate['officialClientAcceptance']);
        self::assertFalse($candidate['publishable']);
        self::assertTrue($candidate['portalAuthorityRequired']);
        self::assertFalse($meta['publishable']);
        self::assertFalse($meta['officialClientAcceptance']);
        self::assertStringContainsString('noindex, nofollow',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringContainsString('NOT DELIVERED',Artisan::output());
    }

    public function test_landing_mode_does_not_activate_a_form_or_provider(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW=1');
        self::assertSame(0,Artisan::call(
            'sites:review-fixture',['product'=>'landing_page']
        ));
        $html=(string)file_get_contents(
            $this->fixtureDir('landing_page').'/index.html'
        );
        self::assertTrue(InertPreviewAudit::isSafe($html));
        self::assertStringContainsString('data-site-product="landing-page"',$html);
        self::assertStringNotContainsString('<script',$html);
    }

    public function test_disallowed_product_and_existing_target_are_denied(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW=1');
        self::assertSame(1,Artisan::call(
            'sites:review-fixture',['product'=>'../../unsafe']
        ));
        self::assertSame(0,Artisan::call(
            'sites:review-fixture',['product'=>'onepage']
        ));
        self::assertSame(1,Artisan::call(
            'sites:review-fixture',['product'=>'onepage']
        ));
    }

    public function test_symlink_target_is_never_written(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW=1');
        $target=$this->fixtureDir('onepage');
        $external=storage_path('app/private/sites-f1-37-untouched-marker.txt');
        file_put_contents($external,'KEEP');
        self::assertTrue(symlink($external,$target));
        try {
            self::assertSame(1,Artisan::call(
                'sites:review-fixture',['product'=>'onepage']
            ));
            self::assertSame('KEEP',(string)file_get_contents($external));
        } finally {
            @unlink($target);
            @unlink($external);
        }
    }
}
