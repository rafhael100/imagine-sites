<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-57: fixture-only visual theme navigation. No customer/self-service edit,
 * client approval, public HTTP route, payment or publication.
 */
final class SyntheticReviewThemeSelectorTest extends TestCase
{
    private const ROUTE='/_lab/sites/v1/review';

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_EXPORT');
        parent::setUp();
    }

    protected function tearDown():void
    {
        putenv('SITES_SYNTHETIC_EXPORT');
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        putenv('SITES_SYNTHETIC_HTTP');
        parent::tearDown();
    }

    /** @return array{string,string} outer and exact unescaped preview HTML */
    private function preview(string $query):array
    {
        $response=$this->get(self::ROUTE.$query)->assertOk();
        $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
        $response->assertHeader('X-Frame-Options','DENY');
        $outer=(string)$response->getContent();
        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$m));
        $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        self::assertSame(1,preg_match(
            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$outer,$digest));
        self::assertSame(hash('sha256',$inner),$digest[1]);
        self::assertStringContainsString('sandbox=""',$outer);
        self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$outer);
        self::assertStringContainsString('Aceite oficial pendente no Portal',$outer);
        self::assertStringNotContainsString('<form',$inner);
        self::assertStringNotContainsString('<script',$inner);
        return [$outer,$inner];
    }

    public function test_all_six_product_and_style_combinations_are_distinct_and_safe():void
    {
        foreach(['onepage','landing_page'] as $product) {
            $digests=[];
            foreach(['classic','modern','warm'] as $preset) {
                [$outer,$inner]=$this->preview(
                    '?product='.$product.'&device=mobile&preset='.$preset
                );
                $expected='release-f38-'.$product.
                    ($preset==='classic'?'':'-'.$preset);
                self::assertStringContainsString($expected,$outer);
                self::assertStringContainsString('aria-label="Alternar tema"',$outer);
                self::assertStringContainsString('class="browser mobile"',$outer);
                self::assertStringContainsString('data-site-product="'.
                    ($product==='onepage'?'onepage':'landing-page').'"',$inner);
                self::assertStringContainsString(
                    'href="/_lab/sites/v1/review?product=onepage&amp;device=mobile&amp;preset='.$preset.'"',
                    $outer
                );
                self::assertStringContainsString(
                    'href="/_lab/sites/v1/review?product='.$product.
                    '&amp;device=desktop&amp;preset='.$preset.'"',
                    $outer
                );
                foreach(['classic','modern','warm'] as $linkPreset) {
                    self::assertStringContainsString(
                        'href="/_lab/sites/v1/review?product='.$product.
                        '&amp;device=mobile&amp;preset='.$linkPreset.'"',
                        $outer
                    );
                }
                if($preset==='classic') {
                    self::assertStringNotContainsString('visual-preset: ',$inner);
                } else {
                    self::assertStringContainsString('visual-preset: '.$preset,$inner);
                }
                self::assertStringContainsString('Trocar o tema exige nova revisão interna',$outer);
                self::assertStringContainsString('Publicável',$outer);
                self::assertStringContainsString('Não registrado',$outer);
                $digests[]=hash('sha256',$inner);
            }
            self::assertCount(3,array_unique($digests));
        }
    }

    public function test_default_classic_preserves_old_compatibility_and_hash():void
    {
        [$oldOuter,$oldInner]=$this->preview('?product=onepage&device=desktop');
        [$newOuter,$newInner]=$this->preview(
            '?product=onepage&device=desktop&preset=classic'
        );
        self::assertSame($oldInner,$newInner);
        self::assertStringContainsString('release-f38-onepage',$oldOuter);
        self::assertStringContainsString('release-f38-onepage',$newOuter);
    }

    public function test_untrusted_theme_values_are_denied_before_rendering():void
    {
        foreach([
            '?preset=',
            '?preset=Premium',
            '?preset=modern%2F..',
            '?preset[]=warm',
            '?preset=%3Cscript%3E',
            '?product=onepage&preset=undefined',
            '?device=unknown&preset=classic',
        ] as $query) {
            $this->get(self::ROUTE.$query)->assertNotFound();
        }
    }

    public function test_export_mode_keeps_original_classic_only_four_page_gallery():void
    {
        putenv('SITES_SYNTHETIC_EXPORT=1');
        [$outer,$inner]=$this->preview('?product=onepage&device=desktop');
        self::assertStringNotContainsString('aria-label="Alternar tema"',$outer);
        self::assertStringContainsString(
            'href="/_lab/sites/v1/review?product=onepage&amp;device=mobile"',$outer);
        self::assertStringNotContainsString('visual-preset: ',$inner);
    }

    public function test_theme_selection_cannot_create_an_approval_endpoint():void
    {
        $this->post(self::ROUTE.'?preset=modern')->assertStatus(405);
        $this->get('/_lab/sites/v1/approve?preset=warm')->assertNotFound();
        $this->get('/api/publish?preset=modern')->assertNotFound();
    }
}
