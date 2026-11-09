<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SyntheticPreviewCommandTest extends TestCase
{
    private function outputPath(string $product): string
    {
        return storage_path('app/private/sites-f1-07-'.$product.'.html');
    }

    protected function tearDown(): void
    {
        foreach (['onepage', 'landing_page'] as $product) {
            @unlink($this->outputPath($product));
        }
        putenv('SITES_SYNTHETIC_PREVIEW');
        parent::tearDown();
    }

    public function test_command_denies_by_default_even_in_test_environment(): void
    {
        putenv('SITES_SYNTHETIC_PREVIEW');
        self::assertSame(1, Artisan::call('sites:preview-fixture', ['product' => 'onepage']));
        self::assertFileDoesNotExist($this->outputPath('onepage'));
    }

    public function test_explicit_lab_switch_renders_exact_html_into_private_storage_only(): void
    {
        putenv('SITES_SYNTHETIC_PREVIEW=1');
        self::assertSame(0, Artisan::call('sites:preview-fixture', ['product' => 'onepage']));
        $path = $this->outputPath('onepage');
        self::assertFileExists($path);
        $html = (string)file_get_contents($path);
        self::assertStringStartsWith('<!doctype html>', $html);
        self::assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        self::assertStringContainsString('data-site-product="onepage"', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString(hash('sha256', $html), Artisan::output());
    }

    public function test_landing_fixture_generates_different_render_mode(): void
    {
        putenv('SITES_SYNTHETIC_PREVIEW=1');
        self::assertSame(0, Artisan::call('sites:preview-fixture', ['product' => 'landing_page']));
        $html = (string)file_get_contents($this->outputPath('landing_page'));
        self::assertStringContainsString('data-site-product="landing-page"', $html);
        self::assertStringNotContainsString('id="sobre"', $html);
    }

    public function test_path_traversal_or_unknown_product_is_refused(): void
    {
        putenv('SITES_SYNTHETIC_PREVIEW=1');
        self::assertSame(1, Artisan::call('sites:preview-fixture', ['product' => '../../outside']));
        self::assertFileDoesNotExist($this->outputPath('onepage'));
    }
}
