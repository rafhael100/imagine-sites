<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-290: UI shell test is in-process only and cannot approve a release.
 * Customer HTML inside sandboxed srcdoc must remain unchanged in content.
 */
final class SyntheticImagineShellIdentityTest extends TestCase
{
    private const PATH = '/_lab/sites/v1/review';

    protected function setUp(): void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        putenv('SITES_SYNTHETIC_HTTP');
        parent::tearDown();
    }

    public function test_original_branding_wraps_only_inert_customer_preview(): void
    {
        foreach (['onepage','landing_page'] as $product) {
            foreach (['desktop','mobile'] as $device) {
                $response = $this->get(self::PATH.'?product='.$product.
                    '&device='.$device.'&preset=classic')->assertOk();
                $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                $response->assertHeader('X-Frame-Options','DENY');
                self::assertStringContainsString('no-store',
                    (string)$response->headers->get('Cache-Control'));
                $csp=(string)$response->headers->get('Content-Security-Policy');
                foreach (["img-src 'self' data:", "script-src 'none'",
                    "connect-src 'none'", "form-action 'none'",
                    "frame-ancestors 'none'"] as $directive) {
                    self::assertStringContainsString($directive,$csp);
                }
                $outer=(string)$response->getContent();
                foreach ([
                    'src="/assets/logo-imagine.png"', 'alt="Imagine"',
                    '--imagine-orange: #f9941d;',
                    '--imagine-ink: #171b19;',
                    '--imagine-white: #ffffff;',
                    '--imagine-surface: #f7f7f8;',
                    '--imagine-orange-text: #9b4d00;',
                    '--imagine-focus: #b75c00;',
                    'Laboratório de revisão visual',
                    'Aprovação, publicação e entrega desabilitadas',
                ] as $token) {
                    self::assertStringContainsString($token,$outer);
                }
                self::assertStringContainsString('sandbox=""',$outer);
                self::assertStringNotContainsString('<script',$outer);
                self::assertStringNotContainsString('<form',$outer);
                self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$match));
                $inner=html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                self::assertStringContainsString('noindex, nofollow',$inner);
                self::assertStringContainsString('data-site-product="', $inner);
                self::assertStringNotContainsString('--imagine-orange',$inner,
                    'Only the staff shell uses Imagine brand tokens');
                self::assertStringNotContainsString('/assets/logo-imagine.png',$inner,
                    'No internal branding is injected into customer pages');
                self::assertStringNotContainsString('<form',$inner);
                self::assertStringNotContainsString('<script',$inner);
                self::assertSame(1,preg_match(
                    '/id="artifact-digest">([0-9a-f]{64})<\/code>/',$outer,$digest));
                self::assertSame(hash('sha256',$inner),$digest[1],
                    'Shell migration must not change customer artifact SHA');
            }
        }
    }

    public function test_anonymous_remote_request_still_denied(): void
    {
        $this->withServerVariables(['REMOTE_ADDR'=>'198.51.100.22'])
            ->get(self::PATH)->assertNotFound();
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        try {
            $this->get(self::PATH)->assertNotFound();
        } finally {
            putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        }
    }
}
