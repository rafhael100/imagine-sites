<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-203: every offline combination of theme, palette, layout and nav.
 * No real identity, no external HTTP, no acceptance or publishing controls.
 */
final class SyntheticCompositeVisualReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/visual-change';
    private const ORDERS=[
        'services_process_faq'=>['highlights','process','faq'],
        'services_faq_process'=>['highlights','faq','process'],
        'process_services_faq'=>['process','highlights','faq'],
        'process_faq_services'=>['process','faq','highlights'],
        'faq_services_process'=>['faq','highlights','process'],
        'faq_process_services'=>['faq','process','highlights'],
    ];
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP',
        'SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW',
        'SITES_SYNTHETIC_ORDER_REVIEW',
        'SITES_SYNTHETIC_ANCHOR_REVIEW',
        'SITES_F181_COMBINED_LAYOUT',
        'SITES_F191_BRAND_REVIEW',
        'SITES_F203_COMPOSITE_REVIEW',
    ];

    protected function setUp():void
    {
        foreach(self::FLAGS as $name)putenv($name.'=1');
        parent::setUp();
    }
    protected function tearDown():void
    {
        foreach(self::FLAGS as $name)putenv($name);
        parent::tearDown();
    }

    public function test_all_216_variants_render_safe_unique_html_and_only_true_changes_reset_review():void
    {
        $shaByVisual=[];
        $count=0;
        $baselineCount=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(self::ORDERS as $orderCode=>$order) {
                    foreach($product==='onepage'?['off','on']:['off'] as $nav) {
                        foreach(['default','ocean','plum','forest'] as $palette) {
                            $query='?product='.$product.'&preset='.$preset.
                                '&device=desktop&order='.$orderCode.
                                '&nav='.$nav.'&palette='.$palette;
                            $res=$this->get(self::PATH.$query)->assertOk();
                            $res->assertHeader('X-Frame-Options','DENY');
                            $res->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                            self::assertStringContainsString('no-store',
                                (string)$res->headers->get('Cache-Control'));
                            $csp=(string)$res->headers->get('Content-Security-Policy');
                            foreach(["script-src 'none'","connect-src 'none'",
                                "form-action 'none'","frame-ancestors 'none'"] as $directive) {
                                self::assertStringContainsString($directive,$csp);
                            }
                            $html=(string)$res->getContent();
                            self::assertStringContainsString('Ordem das seções da página',$html);
                            self::assertStringContainsString('Navegação interna da Onepage',$html);
                            self::assertStringContainsString('Cores da identidade visual',$html);
                            self::assertStringContainsString('Aceite oficial pendente no Portal',$html);
                            self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$html);
                            self::assertStringNotContainsString('<script',$html);
                            self::assertStringNotContainsString('<form',$html);
                            self::assertSame(1,preg_match(
                                '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                                $html,$shown
                            ));
                            $hasChange=($preset!=='classic' ||
                                $orderCode!=='services_process_faq' ||
                                $nav!=='off' || $palette!=='default');
                            $expectedFrames=$hasChange?2:1;
                            self::assertSame($expectedFrames,preg_match_all(
                                '/\bsrcdoc="([^"]+)"/s',$html,$frame
                            ));
                            $inner=html_entity_decode(
                                $frame[1][$expectedFrames-1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                            );
                            self::assertSame($shown[1],hash('sha256',$inner));
                            self::assertStringContainsString('noindex, nofollow',$inner);
                            self::assertStringNotContainsString('<script',$inner);
                            self::assertStringNotContainsString('<form',$inner);
                            foreach(['highlights','process','faq'] as $section) {
                                self::assertSame(1,substr_count(
                                    $inner,'<section class="'.$section.'"'
                                ));
                            }
                            $positions=[];
                            foreach($order as $name) {
                                $needle=match($name) {
                                    'highlights'=>'id="solucoes"',
                                    'process'=>'id="etapas"',
                                    'faq'=>'id="perguntas"',
                                };
                                $p=strpos($inner,$needle);
                                self::assertNotFalse($p);
                                $positions[]=$p;
                            }
                            self::assertTrue($positions[0]<$positions[1] &&
                                $positions[1]<$positions[2]);
                            foreach(['#solucoes','#etapas','#perguntas'] as $fragment) {
                                if($nav==='on') self::assertStringContainsString(
                                    '<a href="'.$fragment.'">',$inner
                                );
                                else self::assertStringNotContainsString(
                                    '<a href="'.$fragment.'">',$inner
                                );
                            }
                            if($palette==='default'){
                                self::assertStringNotContainsString(
                                    '.hero .eyebrow { color: #ffffff; opacity: 1; }',$inner
                                );
                            }else{
                                self::assertStringContainsString(
                                    '.hero .eyebrow { color: #ffffff; opacity: 1; }',$inner
                                );
                            }
                            if($hasChange) {
                                self::assertStringContainsString(
                                    'Comparação antes e depois do layout',$html
                                );
                                self::assertStringContainsString(
                                    '4 — rascunho, sem aceite',$html
                                );
                                self::assertStringContainsString(
                                    'Antes da alteração visual',$html
                                );
                                self::assertStringContainsString(
                                    'id="visual-style-changed">'.($preset==='classic'?'Não':'Sim'),
                                    $html
                                );
                                self::assertStringContainsString(
                                    'id="visual-palette-changed">'.($palette==='default'?'Não':'Sim'),
                                    $html
                                );
                                self::assertSame(1,preg_match(
                                    '/id="layout-previous-digest">([a-f0-9]{64})<\/code>/',
                                    $html,$previous
                                ));
                                self::assertNotSame($previous[1],$shown[1]);
                                $oldHtml=html_entity_decode(
                                    $frame[1][0],ENT_QUOTES|ENT_HTML5,'UTF-8'
                                );
                                self::assertSame($previous[1],hash('sha256',$oldHtml));
                                self::assertStringContainsString(
                                    'sandbox="" tabindex="-1" referrerpolicy="no-referrer" srcdoc=',
                                    $html,'Previous preview must not trap keyboard'
                                );
                                self::assertStringNotContainsString(
                                    'Configuração visual original',$html
                                );
                                self::assertStringContainsString(
                                    'release-f203-after-'.$product.'-'.$preset.'-'.$orderCode.'-'.
                                    $palette.'-'.$nav,$html
                                );
                            }else {
                                ++$baselineCount;
                                self::assertStringContainsString(
                                    'Configuração visual original',$html
                                );
                                self::assertStringContainsString(
                                    'Sem alterações propostas',$html
                                );
                                self::assertStringNotContainsString(
                                    'id="visual-style-changed"',$html
                                );
                                self::assertStringNotContainsString(
                                    'Comparação antes e depois do layout',$html
                                );
                                self::assertStringContainsString(
                                    'release-f203-before-'.$product,$html
                                );
                            }
                            $signature=$product.'|'.$preset.'|'.$orderCode.
                                '|'.$nav.'|'.$palette;
                            $shaByVisual[$signature]=$shown[1];
                            ++$count;
                        }
                    }
                }
            }
        }
        self::assertSame(216,$count);
        self::assertSame(2,$baselineCount);
        self::assertCount(216,array_unique($shaByVisual),
            'Each product/preset/order/nav/palette combination has separate HTML bytes');
    }

    public function test_all_four_controls_preserve_selection_when_switching_tabs():void
    {
        $query='?product=onepage&device=desktop&preset=modern'.
            '&order=faq_process_services&nav=on&palette=plum';
        $html=(string)$this->get(self::PATH.$query)->assertOk()->getContent();
        foreach([
            '?product=onepage&amp;device=desktop&amp;preset=classic&amp;order=faq_process_services&amp;nav=on&amp;palette=plum',
            '?product=onepage&amp;device=mobile&amp;preset=modern&amp;order=faq_process_services&amp;nav=on&amp;palette=plum',
            '?product=onepage&amp;device=desktop&amp;preset=modern&amp;order=faq_services_process&amp;nav=on&amp;palette=plum',
            '?product=onepage&amp;device=desktop&amp;preset=modern&amp;nav=off&amp;order=faq_process_services&amp;palette=plum',
            '?product=onepage&amp;device=desktop&amp;preset=modern&amp;palette=ocean&amp;order=faq_process_services&amp;nav=on',
            '?product=landing_page&amp;device=desktop&amp;preset=modern&amp;order=faq_process_services&amp;nav=off&amp;palette=plum',
        ] as $hrefFragment) {
            self::assertStringContainsString($hrefFragment,$html,
                'Switching a setting must preserve other safe visual options');
        }
    }

    public function test_invalid_selectors_and_missing_flags_never_render_private_artifacts():void
    {
        $valid='?order=faq_process_services&nav=off&palette=forest';
        foreach([
            '',
            '?order=faq_process_services&nav=off',
            '?order=faq_process_services&nav=off&palette=invalid',
            '?order=faq_process_services&nav=off&palette[]=ocean',
            '?order[]=faq_process_services&nav=off&palette=forest',
            '?order=faq_process_services&nav[]=on&palette=forest',
            '?product=landing_page&order=faq_process_services&nav=on&palette=forest',
            '?product=blog&order=faq_process_services&nav=off&palette=forest',
            '?device=tablet&order=faq_process_services&nav=off&palette=forest',
            '?preset=unknown&order=faq_process_services&nav=off&palette=forest',
            '?order=faq_process_services&nav=off&palette=forest&clientId=42',
            '?order=faq_process_services&nav=off&palette=forest&publishable=true',
        ] as $query) $this->get(self::PATH.$query)->assertNotFound();

        foreach(self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get(self::PATH.$valid)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.10'])
            ->get(self::PATH.$valid)->assertNotFound();
        $this->post(self::PATH.$valid)->assertStatus(405);
    }

    public function test_old_palette_and_layout_reviewers_do_not_gain_new_controls():void
    {
        $old=(string)$this->get(
            '/_lab/sites/v1/brand-palette?palette=ocean'
        )->assertOk()->getContent();
        self::assertStringNotContainsString('Antes da alteração visual',$old);
        self::assertStringNotContainsString('Configuração visual original',$old);
        $other=(string)$this->get(
            '/_lab/sites/v1/layout-options?order=faq_services_process&nav=off'
        )->assertOk()->getContent();
        self::assertStringNotContainsString('Antes da alteração visual',$other);
    }
}
