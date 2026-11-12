<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-191: closed palette catalog, never customer CSS/real brand identity. */
final class SyntheticBrandPaletteReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/brand-palette';
    private const PALETTES=[
        'default'=>null,
        'ocean'=>['#064e5b','#075a6b','#ffffff','#075a6b'],
        'plum'=>['#50204d','#673060','#ffe9fa','#50204d'],
        'forest'=>['#134a36','#1d5a43','#f4fff5','#134a36'],
    ];

    protected function setUp():void
    {
        foreach([
            'SITES_SYNTHETIC_HTTP',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_F191_BRAND_REVIEW',
        ] as $flag)putenv($flag.'=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach([
            'SITES_F191_BRAND_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_all_palette_options_have_new_digest_and_mobile_parity():void
    {
        $hashes=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(self::PALETTES as $option=>$colors) {
                    $byDevice=[];
                    foreach(['desktop','mobile'] as $device) {
                        $query='?product='.$product.'&device='.$device.
                            '&preset='.$preset.'&palette='.$option;
                        $res=$this->get(self::PATH.$query)->assertOk();
                        $res->assertHeader('X-Frame-Options','DENY');
                        $res->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                        $res->assertHeader('Referrer-Policy','no-referrer');
                        self::assertStringContainsString('no-store',
                            (string)$res->headers->get('Cache-Control'));
                        $csp=(string)$res->headers->get('Content-Security-Policy');
                        foreach(["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $fragment) {
                            self::assertStringContainsString($fragment,$csp);
                        }

                        $outer=(string)$res->getContent();
                        foreach([
                            'Cores da identidade visual',
                            'Compare cores de demonstração',
                            'Aceite oficial pendente no Portal',
                            'Aprovação, publicação e entrega desabilitadas',
                            'sandbox=""',
                            'class="browser '.$device.'"',
                            'release-f191-'.$product.'-'.$preset.'-'.$option,
                        ] as $phrase)self::assertStringContainsString($phrase,$outer);
                        self::assertStringNotContainsString('<script',$outer);
                        self::assertStringNotContainsString('<form',$outer);

                        foreach(array_keys(self::PALETTES) as $available) {
                            self::assertStringContainsString(
                                'href="'.self::PATH.'?product='.$product.
                                '&amp;device='.$device.'&amp;preset='.$preset.
                                '&amp;palette='.$available.'"',
                                $outer
                            );
                        }
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$m
                        ));
                        self::assertSame(1,preg_match(
                            '/\bsrcdoc="([^"]+)"/s',$outer,$frame
                        ));
                        $inner=html_entity_decode($frame[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                        self::assertSame($m[1],hash('sha256',$inner));
                        foreach(['process','faq','highlights'] as $section) {
                            self::assertSame(1,substr_count($inner,'<section class="'.$section.'"'));
                        }
                        self::assertStringContainsString('noindex, nofollow',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('url(',$inner);
                        if($colors===null) {
                            self::assertStringContainsString(
                                'Tema original sem alterações nas cores da marca.',$outer
                            );
                            self::assertStringNotContainsString(
                                '.hero .eyebrow { color: #ffffff; opacity: 1; }',$inner
                            );
                        }else{
                            [$start,$end,$bg,$fg]=$colors;
                            self::assertStringContainsString(
                                '.hero { background: linear-gradient(120deg, '.
                                $start.', '.$end.'); color: #ffffff; }',$inner
                            );
                            self::assertStringContainsString(
                                '.hero .btn { background: '.$bg.'; color: '.$fg.'; }',
                                $inner
                            );
                            self::assertStringContainsString(
                                'Os três pares verificados atingem pelo menos 4,5:1',$outer
                            );
                            self::assertSame(1,preg_match(
                                '/Contraste do botão.*?([0-9]+,[0-9]):1/s',$outer
                            ));
                        }
                        $byDevice[$device]=[$m[1],$inner];
                    }
                    self::assertSame($byDevice['desktop'],$byDevice['mobile']);
                    $hashes[]=$byDevice['desktop'][0];
                }
            }
        }
        self::assertCount(24,array_unique($hashes),
            '2 products x 3 styles x 4 palettes are distinct review-only versions');
    }

    public function test_unknown_queries_flags_and_public_ip_are_denied():void
    {
        foreach([
            '',
            '?palette=',
            '?palette=blue',
            '?palette[]=ocean',
            '?palette=%23006466',
            '?palette=default&bg=%23ffffff',
            '?palette=ocean&customCss=color:red',
            '?palette=ocean&publishable=true',
            '?palette=ocean&clientId=123',
            '?palette=ocean&product=blog',
            '?palette=ocean&product[]=onepage',
            '?palette=ocean&device=tablet',
            '?palette=ocean&preset=custom',
        ] as $query)$this->get(self::PATH.$query)->assertNotFound();

        $valid='?palette=ocean';
        foreach([
            'SITES_F191_BRAND_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag) {
            putenv($flag);
            try {$this->get(self::PATH.$valid)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.103'])
            ->get(self::PATH.$valid)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post(self::PATH.$valid)->assertStatus(405);
    }

    public function test_prior_review_route_does_not_inherit_palette_settings():void
    {
        $prior=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        $html=(string)$prior->getContent();
        self::assertStringNotContainsString('Cores da identidade visual',$html);
        self::assertStringNotContainsString('&amp;palette=',$html);
    }
}
