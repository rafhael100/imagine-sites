<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-188: private before/after view of a real domain review-reset proposal. */
final class SyntheticCombinedLayoutDiffReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/layout-diff';
    private const ORDERS=[
        'services_process_faq'=>['highlights','process','faq'],
        'services_faq_process'=>['highlights','faq','process'],
        'process_services_faq'=>['process','highlights','faq'],
        'process_faq_services'=>['process','faq','highlights'],
        'faq_services_process'=>['faq','highlights','process'],
        'faq_process_services'=>['faq','process','highlights'],
    ];

    protected function setUp():void
    {
        foreach([
            'SITES_SYNTHETIC_HTTP',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_ORDER_REVIEW',
            'SITES_SYNTHETIC_ANCHOR_REVIEW',
            'SITES_F181_COMBINED_LAYOUT',
            'SITES_F188_LAYOUT_DIFF',
        ] as $flag)putenv($flag.'=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach([
            'SITES_F188_LAYOUT_DIFF',
            'SITES_F181_COMBINED_LAYOUT',
            'SITES_SYNTHETIC_ANCHOR_REVIEW',
            'SITES_SYNTHETIC_ORDER_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_48_changed_layout_proposals_show_review_reset_with_exact_bytes():void
    {
        $unique=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(self::ORDERS as $orderCode=>$order) {
                    foreach($product==='onepage'?['off','on']:['off'] as $mode) {
                        if($orderCode==='services_process_faq' && $mode==='off')continue;
                        $display=[];
                        foreach(['desktop','mobile'] as $device) {
                            $query='?product='.$product.'&preset='.$preset.
                                '&device='.$device.'&order='.$orderCode.
                                '&nav='.$mode;
                            $res=$this->get(self::PATH.$query)->assertOk();
                            $res->assertHeader('X-Frame-Options','DENY');
                            $res->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                            self::assertStringContainsString('no-store',
                                (string)$res->headers->get('Cache-Control'));
                            $csp=(string)$res->headers->get('Content-Security-Policy');
                            foreach(["script-src 'none'","connect-src 'none'",
                                "form-action 'none'","frame-ancestors 'none'"] as $part) {
                                self::assertStringContainsString($part,$csp);
                            }
                            $outer=(string)$res->getContent();
                            foreach([
                                'Comparação antes e depois do layout',
                                'Revisão reaberta',
                                '4 — rascunho, sem aceite',
                                'A decisão anterior não acompanha o novo layout',
                                'A prévia nova é apenas um renderizador de laboratório',
                                'Aceite oficial pendente no Portal',
                                'Aprovação, publicação e entrega desabilitadas',
                                'sandbox=""',
                                'class="browser '.$device.'"',
                                'release-f188-after-'.$product.'-'.$preset.'-'.$orderCode.'-'.$mode,
                            ] as $phrase) {
                                self::assertStringContainsString($phrase,$outer);
                            }
                            self::assertStringNotContainsString('<script',$outer);
                            self::assertStringNotContainsString('<form',$outer);
                            self::assertSame(1,preg_match(
                                '/id="layout-previous-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$before
                            ));
                            self::assertSame(1,preg_match(
                                '/id="layout-next-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$after
                            ));
                            self::assertNotSame($before[1],$after[1]);
                            self::assertSame(1,preg_match(
                                '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$shown
                            ));
                            self::assertSame($after[1],$shown[1]);
                            self::assertSame(2,preg_match_all(
                                '/\bsrcdoc="([^"]+)"/s',$outer,$frames
                            ),'Exact old and new sandboxed HTML must both be available');
                            $previous=html_entity_decode(
                                $frames[1][0],ENT_QUOTES|ENT_HTML5,'UTF-8'
                            );
                            $inner=html_entity_decode(
                                $frames[1][1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                            );
                            self::assertSame(hash('sha256',$previous),$before[1]);
                            self::assertSame(hash('sha256',$inner),$after[1]);
                            self::assertStringNotContainsString('<script',$previous);
                            self::assertStringNotContainsString('<form',$previous);
                            self::assertStringContainsString('noindex, nofollow',$previous);
                            self::assertStringNotContainsString('<form',$inner);
                            self::assertStringNotContainsString('<script',$inner);
                            self::assertStringContainsString('noindex, nofollow',$inner);
                            self::assertStringContainsString(
                                'id="layout-prior-revision">3',$outer
                            );
                            self::assertStringContainsString(
                                'sandbox="" tabindex="-1" referrerpolicy="no-referrer" srcdoc=',$outer
                            );
                            self::assertStringContainsString(
                                'sandbox="" tabindex="0" referrerpolicy="no-referrer" srcdoc=',$outer
                            );

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
                            $expectOrder=$orderCode!=='services_process_faq';
                            self::assertStringContainsString(
                                'id="layout-order-changed">'.($expectOrder?'Sim':'Não'),
                                $outer
                            );
                            self::assertStringContainsString(
                                'id="layout-navigation-changed">'.
                                ($mode==='on'?'Sim':'Não'),
                                $outer
                            );
                            if($mode==='on'){
                                self::assertSame('onepage',$product);
                                self::assertStringContainsString(
                                    '<a href="#solucoes">Soluções</a>',$inner
                                );
                            }else{
                                self::assertStringNotContainsString(
                                    '<a href="#solucoes">',$inner
                                );
                            }
                            $display[$device]=[$before[1],$after[1],$inner];
                        }
                        self::assertSame($display['desktop'],$display['mobile']);
                        $unique[]=$display['desktop'][1];
                    }
                }
            }
        }
        self::assertCount(48,array_unique($unique),
            'All 48 changed layouts are separately content-addressed');
    }

    public function test_unchanged_original_and_invalid_client_settings_deny():void
    {
        foreach([
            '',
            '?order=services_process_faq&nav=off',
            '?order=services_process_faq&nav=on&product=landing_page',
            '?order=does_not_exist&nav=on',
            '?order=services_faq_process&nav[]=on',
            '?order[]=faq_process_services&nav=off',
            '?order=faq_process_services&nav=off&clientId=123',
            '?order=faq_process_services&nav=off&publishable=true',
            '?order=faq_process_services&nav=off&preset=evil',
            '?order=faq_process_services&nav=off&device=tablet',
        ] as $query) {
            $this->get(self::PATH.$query)->assertNotFound();
        }
        $valid='?order=services_faq_process&nav=off';
        foreach([
            'SITES_F188_LAYOUT_DIFF',
            'SITES_F181_COMBINED_LAYOUT',
            'SITES_SYNTHETIC_ANCHOR_REVIEW',
            'SITES_SYNTHETIC_ORDER_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag) {
            putenv($flag);
            try {$this->get(self::PATH.$valid)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.77'])
            ->get(self::PATH.$valid)->assertNotFound();
        $this->post(self::PATH.$valid)->assertStatus(405);
    }

    public function test_older_layout_comparer_does_not_inherit_review_reset_ui():void
    {
        $old=$this->get('/_lab/sites/v1/layout-options'.
            '?order=services_faq_process&nav=on')->assertOk();
        self::assertStringNotContainsString(
            'Comparação antes e depois do layout',(string)$old->getContent()
        );
        self::assertStringNotContainsString(
            'layout-previous-digest',(string)$old->getContent()
        );
    }
}
