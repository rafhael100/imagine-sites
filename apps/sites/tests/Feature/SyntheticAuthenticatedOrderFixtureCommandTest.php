<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\InertPreviewAudit;
use Tests\TestCase;

final class SyntheticAuthenticatedOrderFixtureCommandTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['GITHUB_ACTIONS','SITES_AUTHENTICATED_ORDER_LAB'] as $name) {
            $this->previous[$name]=getenv($name);
        }
    }

    private function dir(string $product): string
    {
        return storage_path('app/private/sites-f358-'.$product);
    }

    protected function tearDown(): void
    {
        foreach (['onepage','landing_page'] as $product) {
            $dir=$this->dir($product);
            if(is_link($dir)) {
                @unlink($dir);
                continue;
            }
            if(is_dir($dir)) {
                foreach(['index.html','release-manifest.json','portal-review-candidate.json']
                    as $file) {
                    @unlink($dir.'/'.$file);
                }
                @rmdir($dir);
            }
        }
        @unlink(storage_path('app/private/sites-f358-untouched.txt'));
        foreach($this->previous as $name=>$value) {
            if($value===false) putenv($name);
            else putenv($name.'='.$value);
        }
        parent::tearDown();
    }

    public function test_command_is_inert_without_both_ci_and_lab_flags(): void
    {
        putenv('GITHUB_ACTIONS=false');
        putenv('SITES_AUTHENTICATED_ORDER_LAB=1');
        self::assertSame(1,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'onepage']));
        self::assertDirectoryDoesNotExist($this->dir('onepage'));

        putenv('GITHUB_ACTIONS=true');
        putenv('SITES_AUTHENTICATED_ORDER_LAB');
        self::assertSame(1,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'onepage']));
        self::assertDirectoryDoesNotExist($this->dir('onepage'));
    }

    public function test_onepage_order_produces_only_private_nonpublishable_preview(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('SITES_AUTHENTICATED_ORDER_LAB=1');
        self::assertSame(0,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'onepage']));
        $dir=$this->dir('onepage');
        $html=(string)file_get_contents($dir.'/index.html');
        $manifest=json_decode((string)file_get_contents(
            $dir.'/release-manifest.json'),true,16,JSON_THROW_ON_ERROR);
        $review=json_decode((string)file_get_contents(
            $dir.'/portal-review-candidate.json'),true,16,JSON_THROW_ON_ERROR);

        self::assertTrue(InertPreviewAudit::isSafe($html));
        self::assertSame(hash('sha256',$html),$manifest['artifactDigest']);
        self::assertSame($manifest['artifactDigest'],$review['artifactDigest']);
        self::assertSame('tenant-f358',$review['tenantRef']);
        self::assertSame('site-f358-onepage',$review['siteRef']);
        self::assertSame('release-f358-onepage',$review['releaseRef']);
        self::assertFalse($review['publishable']);
        self::assertFalse($review['officialClientAcceptance']);
        self::assertTrue($review['portalAuthorityRequired']);
        self::assertFalse($manifest['publishable']);
        self::assertFalse($manifest['officialClientAcceptance']);
        self::assertStringContainsString('noindex, nofollow',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringContainsString('NOT DELIVERED',Artisan::output());
    }

    public function test_landing_page_is_inert_and_never_published(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('SITES_AUTHENTICATED_ORDER_LAB=1');
        self::assertSame(0,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'landing_page']));
        $html=(string)file_get_contents($this->dir('landing_page').'/index.html');
        $review=json_decode((string)file_get_contents(
            $this->dir('landing_page').'/portal-review-candidate.json'),
            true,16,JSON_THROW_ON_ERROR);
        self::assertTrue(InertPreviewAudit::isSafe($html));
        self::assertStringContainsString('data-site-product="landing-page"',$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertFalse($review['publishable']);
    }

    public function test_unsafe_product_and_existing_output_cannot_overwrite(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('SITES_AUTHENTICATED_ORDER_LAB=1');
        self::assertSame(1,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'../../unsafe']));
        self::assertSame(0,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'onepage']));
        $html=(string)file_get_contents($this->dir('onepage').'/index.html');
        self::assertSame(1,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'onepage']));
        self::assertSame($html,(string)file_get_contents(
            $this->dir('onepage').'/index.html'));
    }

    public function test_symlink_target_does_not_escape_private_scratch(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('SITES_AUTHENTICATED_ORDER_LAB=1');
        $outside=storage_path('app/private/sites-f358-untouched.txt');
        file_put_contents($outside,'UNCHANGED');
        $target=$this->dir('onepage');
        self::assertTrue(symlink($outside,$target));
        self::assertSame(1,Artisan::call('sites:authenticated-order-fixture',
            ['product'=>'onepage']));
        self::assertSame('UNCHANGED',(string)file_get_contents($outside));
    }
}
