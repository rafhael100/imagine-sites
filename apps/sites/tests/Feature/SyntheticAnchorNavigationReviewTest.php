<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-180: inspect anchor navigation on/off in the private Laravel reviewer. */
final class SyntheticAnchorNavigationReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/anchor-navigation';

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        putenv('SITES_SYNTHETIC_ANCHOR_REVIEW=1');
        parent::setUp();
    }
    protected function tearDown():void
    {
        foreach([
            'SITES_SYNTHETIC_ANCHOR_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    /** @return array{string,string,string} */
    private function preview(
        string $product,string $preset,string $device,string $mode
    ):array {
        $query='?product='.$product.'&device='.$device.
            '&preset='.$preset.'&nav='.$mode;
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
        foreach([
            'Navegação interna da Onepage',
            'Mudar o menu gera novo hash',
            'Revisão interna pendente',
            'Aceite oficial pendente no Portal',
            'Aprovação, publicação e entrega desabilitadas',
            'sandbox=""',
            'class="browser '.$device.'"',
            'release-f180-'.$product.'-'.$preset.'-'.$mode,
        ] as $phrase)self::assertStringContainsString($phrase,$outer);
        self::assertStringNotContainsString('<script',$outer);
        self::assertStringNotContainsString('<form',$outer);
        self::assertSame(1,preg_match(
            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$outer,$digest
        ));
        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$m));
        $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        self::assertSame(hash('sha256',$inner),$digest[1]);
        foreach(['faq','process','highlights'] as $section) {
            self::assertSame(1,substr_count($inner,'<section class="'.$section.'"'));
        }
        self::assertStringContainsString('noindex, nofollow',$inner);
        self::assertStringNotContainsString('<script',$inner);
        self::assertStringNotContainsString('<form',$inner);
        return [$outer,$inner,$digest[1]];
    }

    public function test_onepage_menu_on_off_have_distinct_sha_and_same_copy():void
    {
        foreach(['classic','modern','warm'] as $preset) {
            $sha=[];
            foreach(['off','on'] as $mode) {
                $pages=[];
                foreach(['desktop','mobile'] as $device) {
                    [$outer,$inner,$digest]=$this->preview(
                        'onepage',$preset,$device,$mode
                    );
                    self::assertStringContainsString(
                        'href="'.self::PATH.'?product=onepage&amp;device='.$device.
                        '&amp;preset='.$preset.'&amp;nav='.$mode.'"',$outer
                    );
                    foreach(['off','on'] as $option) {
                        self::assertStringContainsString(
                            'href="'.self::PATH.'?product=onepage&amp;device='.$device.
                            '&amp;preset='.$preset.'&amp;nav='.$option.'"',$outer
                        );
                    }
                    if($mode==='on') {
                        foreach([
                            '<a href="#solucoes">Soluções</a>',
                            '<a href="#etapas">Como funciona</a>',
                            '<a href="#perguntas">FAQ</a>',
                        ] as $link)self::assertStringContainsString($link,$inner);
                    } else {
                        foreach([
                            '<a href="#solucoes">',
                            '<a href="#etapas">',
                            '<a href="#perguntas">',
                        ] as $link)self::assertStringNotContainsString($link,$inner);
                    }
                    $pages[$device]=[$inner,$digest];
                }
                self::assertSame($pages['desktop'],$pages['mobile']);
                $sha[$mode]=$pages['desktop'][1];
            }
            self::assertNotSame($sha['off'],$sha['on'],
                'Toggling optional anchors changes exact HTML and requires review');
        }
    }

    public function test_landing_page_stays_without_extra_navigation():void
    {
        foreach(['classic','modern','warm'] as $preset) {
            foreach(['desktop','mobile'] as $device) {
                [$outer,$inner]=$this->preview(
                    'landing_page',$preset,$device,'off'
                );
                self::assertStringContainsString(
                    'A Landing Page não utiliza menu extra',$outer
                );
                self::assertStringNotContainsString(
                    '<a href="#solucoes">Soluções</a>',$inner
                );
                self::assertStringNotContainsString(
                    'Alternar menu interno',$outer
                );
            }
            $this->get(self::PATH.
                '?product=landing_page&preset='.$preset.'&nav=on'
            )->assertNotFound();
        }
    }

    public function test_invalid_selectors_flags_and_external_ip_are_denied():void
    {
        foreach([
            '',
            '?product=onepage',
            '?nav=',
            '?nav=maybe',
            '?nav[]=on',
            '?product[]=onepage&nav=on',
            '?nav=on&clientId=10',
            '?nav=on&publishable=true',
            '?nav=on&preset=unknown',
            '?nav=on&device=tablet',
        ] as $query) {
            $this->get(self::PATH.$query)->assertNotFound();
        }
        foreach([
            'SITES_SYNTHETIC_ANCHOR_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag) {
            putenv($flag);
            try {$this->get(self::PATH.'?nav=on')->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.99'])
            ->get(self::PATH.'?nav=on')->assertNotFound();
        $this->post(self::PATH.'?nav=on')->assertStatus(405);
    }

    public function test_legacy_review_never_displays_nav_toggle():void
    {
        $old=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        self::assertStringNotContainsString('Navegação interna da Onepage',
            (string)$old->getContent());
        self::assertStringNotContainsString('&amp;nav=',
            (string)$old->getContent());
    }
}
