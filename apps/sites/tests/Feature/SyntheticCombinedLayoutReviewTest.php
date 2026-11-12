<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-181: combine fixed layout order + menu on/off, no public editor. */
final class SyntheticCombinedLayoutReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/layout-options';
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
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        putenv('SITES_SYNTHETIC_ORDER_REVIEW=1');
        putenv('SITES_SYNTHETIC_ANCHOR_REVIEW=1');
        putenv('SITES_F181_COMBINED_LAYOUT=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach([
            'SITES_F181_COMBINED_LAYOUT',
            'SITES_SYNTHETIC_ANCHOR_REVIEW',
            'SITES_SYNTHETIC_ORDER_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_all_valid_combined_layouts_have_dedicated_revisions():void
    {
        $all=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(self::ORDERS as $orderCode=>$order) {
                    foreach($product==='onepage'?['off','on']:['off'] as $navMode) {
                        $perDevice=[];
                        foreach(['desktop','mobile'] as $device) {
                            $query='?product='.$product.'&device='.$device.
                                '&preset='.$preset.'&order='.$orderCode.
                                '&nav='.$navMode;
                            $response=$this->get(self::PATH.$query)->assertOk();
                            $response->assertHeader('X-Frame-Options','DENY');
                            $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                            $response->assertHeader('Referrer-Policy','no-referrer');
                            self::assertStringContainsString('no-store',
                                (string)$response->headers->get('Cache-Control'));
                            $csp=(string)$response->headers->get('Content-Security-Policy');
                            foreach(["script-src 'none'","connect-src 'none'",
                                "form-action 'none'","frame-ancestors 'none'"] as $part) {
                                self::assertStringContainsString($part,$csp);
                            }
                            $outer=(string)$response->getContent();
                            foreach([
                                'Ordem das seções da página',
                                'Navegação interna da Onepage',
                                'Aceite oficial pendente no Portal',
                                'Aprovação, publicação e entrega desabilitadas',
                                'sandbox=""',
                                'class="browser '.$device.'"',
                                'release-f181-'.$product.'-'.$preset.'-'.$orderCode.'-'.$navMode,
                            ] as $phrase) self::assertStringContainsString($phrase,$outer);
                            self::assertStringNotContainsString('<form',$outer);
                            self::assertStringNotContainsString('<script',$outer);
                            self::assertSame(1,preg_match(
                                '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$m
                            ));
                            self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',
                                $outer,$match));
                            $inner=html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                            self::assertSame($m[1],hash('sha256',$inner));
                            self::assertStringNotContainsString('<script',$inner);
                            self::assertStringNotContainsString('<form',$inner);
                            self::assertStringContainsString('noindex, nofollow',$inner);
                            foreach(['faq','process','highlights'] as $name) {
                                self::assertSame(1,substr_count($inner,'<section class="'.$name.'"'));
                            }
                            $positions=[];
                            foreach($order as $part) {
                                $needle=match($part) {
                                    'highlights'=>'id="solucoes"',
                                    'process'=>'id="etapas"',
                                    'faq'=>'id="perguntas"',
                                };
                                $p=strpos($inner,$needle);
                                self::assertNotFalse($p);
                                $positions[]=$p;
                            }
                            self::assertTrue($positions[0]<$positions[1] &&
                                $positions[1]<$positions[2],
                                'Exact optional section order must be preserved');

                            if($navMode==='on') {
                                foreach(['#solucoes','#etapas','#perguntas'] as $anchor) {
                                    self::assertStringContainsString(
                                        '<a href="'.$anchor.'">',$inner
                                    );
                                }
                            } else {
                                foreach(['#solucoes','#etapas','#perguntas'] as $anchor) {
                                    self::assertStringNotContainsString(
                                        '<a href="'.$anchor.'">',$inner
                                    );
                                }
                            }
                            foreach(array_keys(self::ORDERS) as $option) {
                                self::assertStringContainsString(
                                    'href="'.self::PATH.'?product='.$product.
                                    '&amp;device='.$device.'&amp;preset='.$preset.
                                    '&amp;order='.$option.'&amp;nav='.$navMode.'"',
                                    $outer,
                                    'Order tab must preserve nav state'
                                );
                            }
                            if($product==='onepage') {
                                foreach(['off','on'] as $option) {
                                    self::assertStringContainsString(
                                        'href="'.self::PATH.'?product=onepage'.
                                        '&amp;device='.$device.'&amp;preset='.$preset.
                                        '&amp;nav='.$option.'&amp;order='.$orderCode.'"',
                                        $outer,
                                        'Navigation tab must preserve selected order'
                                    );
                                }
                            } else {
                                self::assertStringContainsString(
                                    'A Landing Page não utiliza menu extra',$outer);
                            }
                            $perDevice[$device]=[$m[1],$inner];
                        }
                        self::assertSame($perDevice['desktop'],$perDevice['mobile']);
                        $all[]=$perDevice['desktop'][0];
                    }
                }
            }
        }
        self::assertCount(54,array_unique($all),
            '2 products x 3 presets x 6 orders with navigation only for Onepage');
    }

    public function test_landing_page_cannot_enable_onepage_menu():void
    {
        foreach(array_keys(self::ORDERS) as $code) {
            $this->get(self::PATH.'?product=landing_page'.
                '&order='.$code.'&nav=on')->assertNotFound();
        }
        $outer=(string)$this->get(self::PATH.
            '?product=onepage&order=services_process_faq&nav=on'
        )->assertOk()->getContent();
        self::assertStringContainsString(
            'product=landing_page&amp;device=desktop&amp;preset=classic'.
            '&amp;order=services_process_faq&amp;nav=off',$outer,
            'Product switching from Onepage on must remain valid for Landing off'
        );
    }

    public function test_missing_flags_and_unknown_options_fail_closed():void
    {
        foreach([
            '','?nav=on',
            '?order=services_process_faq',
            '?order=bogus&nav=on',
            '?order[]=services_process_faq&nav=on',
            '?order=services_process_faq&nav[]=on',
            '?order=services_process_faq&nav=invalid',
            '?order=services_process_faq&nav=on&publishable=true',
            '?order=services_process_faq&nav=on&clientId=123',
            '?order=services_process_faq&nav=on&device=tablet',
        ] as $query)$this->get(self::PATH.$query)->assertNotFound();
        $valid='?order=services_process_faq&nav=on';
        foreach([
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
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.42'])
            ->get(self::PATH.$valid)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post(self::PATH.$valid)->assertStatus(405);
    }

    public function test_prior_reviewers_do_not_gain_combined_controls():void
    {
        $old=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        self::assertStringNotContainsString('Navegação interna da Onepage',
            (string)$old->getContent());
        self::assertStringNotContainsString('Ordem das seções da página',
            (string)$old->getContent());
    }
}
