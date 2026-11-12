<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-63 operator-side advisory notes, not a client approval control. */
final class SyntheticEditorialReviewUiTest extends TestCase
{
    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        putenv('SITES_SYNTHETIC_HTTP');
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        parent::tearDown();
    }

    public function test_all_six_theme_previews_show_advisory_only():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                $html=(string)$this->get('/_lab/sites/v1/review?product='.$product.
                    '&device=mobile&preset='.$preset)->assertOk()->getContent();
                self::assertStringContainsString('id="editorial"',$html);
                self::assertStringContainsString('Revisão editorial orientativa',$html);
                self::assertStringContainsString('não substituem a aprovação humana',$html);
                self::assertStringContainsString('Aceite oficial pendente no Portal',$html);
                self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$html);
                self::assertSame(7,substr_count($html,'class="qa-list"')===1 ?
                    substr_count(explode('</ul>',explode('<ul class="qa-list">',$html)[1])[0],'<li>') : -1);
                self::assertSame(1,preg_match(
                    '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$html
                ));
                self::assertStringNotContainsString('name="approve"',$html);
            }
        }
    }

    public function test_advisory_ui_cannot_create_public_routes_or_client_approval():void
    {
        $this->post('/_lab/sites/v1/review?product=onepage')->assertStatus(405);
        $this->post('/_lab/sites/v1/approve')->assertNotFound();
        $this->get('/api/publish')->assertNotFound();
    }
}
