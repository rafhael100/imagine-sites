<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SyntheticVisualReviewHttpTest extends TestCase
{
    private const PATH='/_lab/sites/v1/review';

    protected function setUp(): void
    {
        // Boot gates must exist before the Laravel kernel registers routes.
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

    public function test_onepage_renders_exact_sandboxed_html_with_matching_sha256(): void
    {
        $response=$this->get(self::PATH.'?product=onepage&device=desktop')->assertOk();
        $response->assertHeader('X-Frame-Options','DENY');
        $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
        self::assertStringContainsString('no-store',
            (string)$response->headers->get('Cache-Control'));
        $csp=(string)$response->headers->get('Content-Security-Policy');
        foreach (["script-src 'none'","form-action 'none'","frame-ancestors 'none'"]
            as $directive) {
            self::assertStringContainsString($directive,$csp);
        }

        $outer=(string)$response->getContent();
        self::assertStringContainsString('Laboratório de revisão visual',$outer);
        self::assertStringContainsString('Aceite oficial pendente no Portal',$outer);
        self::assertStringContainsString('class="browser desktop"',$outer);
        self::assertStringContainsString('sandbox=""',$outer);
        self::assertStringContainsString('tabindex="0"',$outer);
        self::assertStringContainsString('referrerpolicy="no-referrer"',$outer);
        self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$outer);
        self::assertStringContainsString('Verificações estruturais',$outer);
        self::assertStringContainsString('Links internos e IDs',$outer);
        self::assertStringContainsString('validação humana posterior',$outer);
        self::assertStringNotContainsString('<form',$outer);
        self::assertStringNotContainsString('<script',$outer);
        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$found));
        $html=html_entity_decode($found[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        self::assertStringStartsWith('<!doctype html>',$html);
        self::assertStringContainsString('data-site-product="onepage"',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertSame(1,preg_match(
            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$outer,$digest));
        self::assertSame(hash('sha256',$html),$digest[1]);
    }

    public function test_landing_mobile_mode_and_product_links_are_navigable(): void
    {
        $r=$this->get(self::PATH.'?product=landing_page&device=mobile')->assertOk();
        $body=(string)$r->getContent();
        self::assertStringContainsString('class="browser mobile"',$body);
        self::assertStringContainsString(
            'href="/_lab/sites/v1/review?product=onepage&amp;device=mobile&amp;preset=classic"',$body);
        self::assertStringContainsString(
            'href="/_lab/sites/v1/review?product=landing_page&amp;device=desktop&amp;preset=classic"',$body);
        self::assertStringContainsString('release-f38-landing_page',$body);
        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$body,$m));
        $html=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        self::assertStringContainsString('data-site-product="landing-page"',$html);
        self::assertStringNotContainsString('id="sobre"',$html);
    }

    public function test_unknown_product_device_and_array_queries_fail_closed(): void
    {
        foreach (['?product=../../secret','?product=blog',
            '?product[]=onepage','?device=tablet','?device[]=mobile',
            '?preset=unknown','?preset[]=modern','?preset='] as $query) {
            $this->get(self::PATH.$query)->assertNotFound();
        }
    }

    public function test_non_loopback_ip_is_denied(): void
    {
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.23'])
            ->get(self::PATH)->assertNotFound();
    }

    public function test_removing_opt_in_flag_denies_existing_lab_route(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        try {
            $this->get(self::PATH)->assertNotFound();
        } finally {
            putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        }
    }

    public function test_no_approval_post_or_real_customer_route_is_available(): void
    {
        foreach(['/admin','/login','/api/publish','/api/sites','/api/leads'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->post(self::PATH)->assertStatus(405);
    }
}
