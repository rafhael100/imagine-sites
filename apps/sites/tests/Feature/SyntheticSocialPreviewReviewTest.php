<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-208: in-process fictional social card reviewer, never a public site. */
final class SyntheticSocialPreviewReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/social-preview';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP',
        'SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW',
        'SITES_F208_SOCIAL_REVIEW',
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

    public function test_all_24_product_theme_mode_device_variants_are_safe_and_pinned():void
    {
        $count=0;
        $unique=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $theme) {
                $modeDigests=[];
                foreach(['off','on'] as $social) {
                    $deviceDigests=[];
                    foreach(['desktop','mobile'] as $device) {
                        $query='?product='.$product.'&preset='.$theme.
                            '&device='.$device.'&social='.$social;
                        $res=$this->get(self::PATH.$query)->assertOk();
                        $res->assertHeader('X-Frame-Options','DENY');
                        $res->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                        $res->assertHeader('Referrer-Policy','no-referrer');
                        self::assertStringContainsString('no-store',
                            (string)$res->headers->get('Cache-Control'));
                        $csp=(string)$res->headers->get('Content-Security-Policy');
                        foreach(["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                            self::assertStringContainsString($rule,$csp);
                        }
                        $outer=(string)$res->getContent();
                        foreach([
                            'Metadados para compartilhamento',
                            'Alternar metadados sociais',
                            'Ativados','Desativados',
                            'Aceite oficial pendente no Portal',
                            'Aprovação, publicação e entrega desabilitadas',
                            'class="browser '.$device.'"',
                            'sandbox=""',
                        ] as $label)self::assertStringContainsString($label,$outer);
                        self::assertStringNotContainsString('<script',$outer);
                        self::assertStringNotContainsString('<form',$outer);
                        foreach(['on','off'] as $next) {
                            self::assertStringContainsString(
                                'href="'.self::PATH.'?product='.$product.
                                '&amp;device='.$device.'&amp;preset='.$theme.
                                '&amp;social='.$next.'"',$outer
                            );
                        }
                        self::assertStringContainsString(
                            '&amp;social='.$social.'"',$outer,
                            'Product, theme and device tabs must retain social choice'
                        );
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$digest
                        ));
                        self::assertSame(1,preg_match(
                            '/\bsrcdoc="([^"]+)"/s',$outer,$frame
                        ));
                        $inner=html_entity_decode(
                            $frame[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        self::assertSame($digest[1],hash('sha256',$inner));
                        self::assertStringContainsString('noindex, nofollow',$inner);
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertStringNotContainsString('og:image',$inner);
                        self::assertStringNotContainsString('og:url',$inner);
                        self::assertStringNotContainsString('twitter:image',$inner);
                        self::assertSame($social==='on'?12:5,
                            substr_count($inner,'<meta '));

                        if ($social==='on') {
                            foreach([
                                'og:type','og:locale','og:title','og:description',
                                'twitter:card','twitter:title','twitter:description',
                            ] as $tag)self::assertStringContainsString($tag,$inner);
                            self::assertStringContainsString(
                                'A versão anterior não transfere sua aprovação',$outer
                            );
                            self::assertStringContainsString(
                                '4 — rascunho, sem aceite',$outer
                            );
                            self::assertSame(1,preg_match(
                                '/id="social-previous-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$before
                            ));
                            self::assertSame(1,preg_match(
                                '/id="social-next-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$after
                            ));
                            self::assertNotSame($before[1],$after[1]);
                            self::assertSame($after[1],$digest[1]);
                            self::assertStringContainsString(
                                'release-f208-social-on-'.$product.'-'.$theme,$outer
                            );
                        } else {
                            self::assertStringContainsString(
                                'Modelo original, sem alteração',$outer
                            );
                            self::assertStringNotContainsString(
                                'id="social-previous-digest"',$outer
                            );
                            self::assertStringContainsString(
                                'release-f208-original-'.$product.'-'.$theme,$outer
                            );
                        }
                        $deviceDigests[$device]=$digest[1];
                        ++$count;
                    }
                    self::assertSame($deviceDigests['desktop'],
                        $deviceDigests['mobile']);
                    $modeDigests[$social]=$deviceDigests['desktop'];
                    $unique[$product.'|'.$theme.'|'.$social]=
                        $deviceDigests['desktop'];
                }
                self::assertNotSame($modeDigests['off'],$modeDigests['on'],
                    'New metadata needs independently reviewed HTML');
            }
        }
        self::assertSame(24,$count);
        self::assertCount(12,array_unique($unique));
    }

    public function test_invalid_queries_and_missing_flags_fail_closed():void
    {
        foreach([
            '', '?product=onepage',
            '?social=maybe',
            '?social[]=on',
            '?social=on&product[]=onepage',
            '?social=on&product=blog',
            '?social=on&preset=custom',
            '?social=on&device=tablet',
            '?social=on&publishable=true',
            '?social=on&clientId=123',
            '?social=on&ogUrl=https://external.invalid',
        ] as $query) $this->get(self::PATH.$query)->assertNotFound();

        foreach(self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get(self::PATH.'?social=on')->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.10'])
            ->get(self::PATH.'?social=on')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post(self::PATH.'?social=on')->assertStatus(405);
    }

    public function test_previous_review_ui_does_not_inherit_social_toggle():void
    {
        $html=(string)$this->get('/_lab/sites/v1/rich-review')
            ->assertOk()->getContent();
        self::assertStringNotContainsString(
            'Metadados para compartilhamento',$html
        );
        self::assertStringNotContainsString('&amp;social=',$html);
    }
}
