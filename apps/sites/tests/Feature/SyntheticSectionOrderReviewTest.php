<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-176: fixed layout permutations in actual private Laravel reviewer.
 * This is a CLI-only visualization, not a client editor or release approval.
 */
final class SyntheticSectionOrderReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/section-order';

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
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach([
            'SITES_SYNTHETIC_ORDER_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $key)putenv($key);
        parent::tearDown();
    }

    public function test_all_six_layouts_render_distinct_content_addressed_pages():void
    {
        $all=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(self::ORDERS as $order=>$arrangement) {
                    $perDevice=[];
                    foreach(['desktop','mobile'] as $device) {
                        $query='?product='.$product.'&device='.$device.
                            '&preset='.$preset.'&order='.$order;
                        $res=$this->get(self::PATH.$query)->assertOk();
                        $res->assertHeader('X-Frame-Options','DENY');
                        $res->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                        $res->assertHeader('Referrer-Policy','no-referrer');
                        $csp=(string)$res->headers->get('Content-Security-Policy');
                        foreach(["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                            self::assertStringContainsString($rule,$csp);
                        }
                        self::assertStringContainsString('no-store',
                            (string)$res->headers->get('Cache-Control'));
                        $html=(string)$res->getContent();
                        self::assertStringContainsString('Ordem das seções da página',$html);
                        self::assertStringContainsString(
                            'A aprovação da disposição anterior não é reutilizada.',$html);
                        self::assertStringContainsString('3 etapas de trabalho incluídas',$html);
                        self::assertStringContainsString('2 soluções e 2 perguntas frequentes',$html);
                        self::assertStringContainsString('Revisão interna pendente',$html);
                        self::assertStringContainsString('Aceite oficial pendente no Portal',$html);
                        self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$html);
                        self::assertStringContainsString('class="browser '.$device.'"',$html);
                        self::assertStringContainsString('sandbox=""',$html);
                        self::assertStringContainsString('release-f176-'.$product.'-'.$preset.'-'.$order,$html);
                        self::assertStringNotContainsString('<script',$html);
                        self::assertStringNotContainsString('<form',$html);

                        foreach(array_keys(self::ORDERS) as $option) {
                            self::assertStringContainsString(
                                'href="'.self::PATH.'?product='.$product.
                                '&amp;device='.$device.'&amp;preset='.$preset.
                                '&amp;order='.$option.'"',$html,
                                'Every section-order option stays in current product/theme/device'
                            );
                        }
                        self::assertStringContainsString(
                            'href="'.self::PATH.'?product='.$product.
                            '&amp;device='.$device.'&amp;preset='.$preset.
                            '&amp;order='.$order.'"',$html);
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                            $html,$hash
                        ));
                        self::assertSame(1,preg_match(
                            '/\bsrcdoc="([^"]+)"/s',$html,$m
                        ));
                        $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                        self::assertSame($hash[1],hash('sha256',$inner));
                        self::assertSame(1,substr_count($inner,'<section class="faq"'));
                        self::assertSame(1,substr_count($inner,'<section class="process"'));
                        self::assertSame(1,substr_count($inner,'<section class="highlights"'));
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringContainsString('noindex, nofollow',$inner);

                        $positions=[];
                        foreach($arrangement as $name) {
                            $section=match($name) {
                                'faq'=>'id="perguntas"',
                                'highlights'=>'id="solucoes"',
                                'process'=>'id="etapas"',
                            };
                            $p=strpos($inner,$section);
                            self::assertNotFalse($p);
                            $positions[]=$p;
                        }
                        self::assertTrue($positions[0]<$positions[1] &&
                            $positions[1]<$positions[2],
                            'Rendered DOM must match selected safe permutation');
                        $perDevice[$device]=[$hash[1],$inner];
                    }
                    self::assertSame($perDevice['desktop'],$perDevice['mobile'],
                        'The same order generates the same exact HTML on both devices');
                    $all[]=$perDevice['desktop'][0];
                }
            }
        }
        self::assertCount(36,array_unique($all),
            'Every product/preset/section order is a unique reviewed artifact');
    }

    public function test_unknown_orders_and_http_like_inputs_fail_closed():void
    {
        foreach([
            '',
            '?product=onepage',
            '?order=',
            '?order[]=services_process_faq',
            '?order=faq_unknown_process',
            '?order=full',
            '?product=unknown&order=services_process_faq',
            '?device=tablet&order=services_process_faq',
            '?preset=unknown&order=services_process_faq',
            '?order=services_process_faq&clientId=123',
            '?order=services_process_faq&publishable=true',
        ] as $query) {
            $this->get(self::PATH.$query)->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.20'])
            ->get(self::PATH.'?order=services_process_faq')->assertNotFound();
        $this->post(self::PATH.'?order=services_process_faq')->assertStatus(405);
    }

    public function test_each_flag_is_required_and_legacy_reviewer_unchanged():void
    {
        foreach([
            'SITES_SYNTHETIC_ORDER_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag) {
            putenv($flag);
            try {
                $this->get(self::PATH.'?order=services_process_faq')->assertNotFound();
            } finally {
                putenv($flag.'=1');
            }
        }
        $old=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        self::assertStringNotContainsString('Ordem das seções da página',
            (string)$old->getContent());
        self::assertStringNotContainsString('&amp;order=',
            (string)$old->getContent());
    }
}
