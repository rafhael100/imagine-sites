<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-161: only in-process Laravel tests can visit the rich review fixture.
 * No public HTTP, client, AI provider, CRM, approval, storage or deployment.
 */
final class SyntheticRichReviewUiTest extends TestCase
{
    private const PATH='/_lab/sites/v1/rich-review';

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        putenv('SITES_SYNTHETIC_EXPORT');
        parent::setUp();
    }

    protected function tearDown():void
    {
        putenv('SITES_SYNTHETIC_RICH_REVIEW');
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        putenv('SITES_SYNTHETIC_HTTP');
        putenv('SITES_SYNTHETIC_EXPORT');
        parent::tearDown();
    }

    public function test_twelve_rich_previews_use_exact_sandboxed_html():void
    {
        $hashes=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                $htmlByDevice=[];
                foreach(['desktop','mobile'] as $device) {
                    $query='?product='.$product.'&device='.$device.'&preset='.$preset;
                    $response=$this->get(self::PATH.$query)->assertOk();
                    $response->assertHeader('X-Frame-Options','DENY');
                    $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                    $response->assertHeader('Referrer-Policy','no-referrer');
                    self::assertStringContainsString('no-store',
                        (string)$response->headers->get('Cache-Control'));
                    $csp=(string)$response->headers->get('Content-Security-Policy');
                    foreach(["script-src 'none'","connect-src 'none'",
                             "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                        self::assertStringContainsString($rule,$csp);
                    }
                    $outer=(string)$response->getContent();
                    self::assertStringContainsString('Laboratório de revisão visual',$outer);
                    self::assertStringContainsString('Conteúdo da página ampliado',$outer);
                    self::assertStringContainsString('2 soluções e 2 perguntas frequentes',$outer);
                    self::assertStringContainsString('Aceite oficial pendente no Portal',$outer);
                    self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$outer);
                    self::assertStringContainsString('sandbox=""',$outer);
                    self::assertStringContainsString('tabindex="0"',$outer);
                    self::assertStringContainsString('class="browser '.$device.'"',$outer);
                    self::assertStringContainsString('href="'.self::PATH.
                        '?product=onepage&amp;device='.$device.'&amp;preset='.$preset.'"',$outer);
                    self::assertStringContainsString('href="'.self::PATH.
                        '?product='.$product.'&amp;device=desktop&amp;preset='.$preset.'"',$outer);
                    self::assertStringNotContainsString('<script',$outer);
                    self::assertStringNotContainsString('<form',$outer);
                    self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$match));
                    $inner=html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                    self::assertStringContainsString('<section class="faq"',$inner);
                    self::assertStringContainsString('<section class="highlights"',$inner);
                    self::assertStringContainsString('data-site-product="'.
                        ($product==='onepage'?'onepage':'landing-page').'"',$inner);
                    self::assertStringContainsString('noindex, nofollow',$inner);
                    self::assertStringNotContainsString('<form',$inner);
                    self::assertStringNotContainsString('<script',$inner);
                    self::assertSame(1,preg_match(
                        '/id="artifact-digest">([a-f0-9]{64})<\/code>/', $outer,$digest
                    ));
                    self::assertSame(hash('sha256',$inner),$digest[1]);
                    self::assertStringContainsString('release-f161-'.$product.'-'.$preset,$outer);
                    $htmlByDevice[$device]=$inner;
                }
                self::assertSame($htmlByDevice['desktop'],$htmlByDevice['mobile'],
                    'device switching changes only the wrapper, not reviewed bytes');
                $hashes[$product][$preset]=hash('sha256',$htmlByDevice['desktop']);
            }
        }
        foreach($hashes as $byPreset) {
            self::assertCount(3,array_unique($byPreset));
        }
        self::assertCount(6,array_unique(array_merge(...array_map('array_values',array_values($hashes)))));
    }

    public function test_legacy_review_stays_unchanged_without_rich_fixture():void
    {
        $response=$this->get('/_lab/sites/v1/review?product=onepage&device=desktop&preset=classic')
            ->assertOk();
        $outer=(string)$response->getContent();
        self::assertStringNotContainsString('Conteúdo da página ampliado',$outer);
        self::assertStringContainsString(
            'href="/_lab/sites/v1/review?product=onepage&amp;device=desktop&amp;preset=classic"',
            $outer);
        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$m));
        $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        self::assertStringNotContainsString('<section class="faq"',$inner);
        self::assertStringNotContainsString('<section class="highlights"',$inner);
    }

    public function test_rejects_unknown_inputs_external_ip_and_post():void
    {
        foreach([
            '?product=blog','?device=tablet','?preset=vip',
            '?product[]=onepage','?device[]=mobile','?preset[]=classic',
            '?product=','?device=','?preset=',
            '?data=leads','?clientId=123',
        ] as $query) {
            $this->get(self::PATH.$query)->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.100'])
            ->get(self::PATH)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post(self::PATH)->assertStatus(405);
        $this->get('/api/sites/publish')->assertNotFound();
    }

    public function test_rich_preview_requires_independent_opt_in():void
    {
        putenv('SITES_SYNTHETIC_RICH_REVIEW');
        try {$this->get(self::PATH)->assertNotFound();}
        finally {putenv('SITES_SYNTHETIC_RICH_REVIEW=1');}

        putenv('SITES_SYNTHETIC_REVIEW_UI');
        try {$this->get(self::PATH)->assertNotFound();}
        finally {putenv('SITES_SYNTHETIC_REVIEW_UI=1');}
    }
}
