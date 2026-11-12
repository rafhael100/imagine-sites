<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-254: private Laravel reviewer for two static conversion layouts. */
final class SyntheticSecondaryCtaReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/secondary-cta-review';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
        'SITES_F254_SECONDARY_CTA_REVIEW',
    ];

    protected function setUp():void
    {
        foreach (self::FLAGS as $flag) putenv($flag.'=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach (self::FLAGS as $flag) putenv($flag);
        parent::tearDown();
    }

    private function url(string $product,string $device,string $preset,string $cta):string
    {
        return self::PATH.'?product='.$product.'&device='.$device.
            '&preset='.$preset.'&cta='.$cta;
    }

    public function test_all_24_private_html_views_are_review_pending_and_exact():void
    {
        $views=0;
        foreach (['onepage','landing_page'] as $product) {
            foreach (['classic','modern','warm'] as $preset) {
                $sha=[];
                foreach (['off','on'] as $mode) {
                    $byDevice=[];
                    foreach (['desktop','mobile'] as $device) {
                        $res=$this->get($this->url($product,$device,$preset,$mode))
                            ->assertOk();
                        $res->assertHeader('X-Frame-Options','DENY');
                        $res->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                        self::assertStringContainsString('no-store',
                            (string)$res->headers->get('Cache-Control'));
                        $csp=(string)$res->headers->get('Content-Security-Policy');
                        foreach (["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                            self::assertStringContainsString($rule,$csp);
                        }

                        $outer=(string)$res->getContent();
                        foreach ([
                            'Comparar CTA secundário',
                            'Revisão interna pendente',
                            'Aceite oficial pendente no Portal',
                            'Aprovação, publicação e entrega desabilitadas',
                            'sandbox=""',
                            'class="browser '.$device.'"',
                            'release-f254-'.$product.'-'.$preset.'-'.$mode,
                        ] as $phrase) self::assertStringContainsString($phrase,$outer);
                        self::assertStringNotContainsString('<script',$outer);
                        self::assertStringNotContainsString('<form',$outer);
                        self::assertSame(1,preg_match(
                            '/id="cta-evidence-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$proof
                        ));
                        self::assertStringContainsString(
                            'Nenhuma; revisão pendente',$outer
                        );
                        $evidenceHashes[$product][$preset][$mode][$device]=$proof[1];

                        foreach (['off','on'] as $option) {
                            self::assertStringContainsString(
                                'href="'.str_replace('&','&amp;',
                                    $this->url($product,$device,$preset,$option)
                                ).'"',$outer
                            );
                        }
                        foreach (['cta-digest-off','cta-digest-on'] as $id) {
                            self::assertSame(1,preg_match(
                                '/id="'.$id.'">([a-f0-9]{64})<\/code>/',$outer,$m
                            ));
                            $selectedHashes[$id]=$m[1];
                        }
                        self::assertNotSame($selectedHashes['cta-digest-off'],
                            $selectedHashes['cta-digest-on']);
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$digest
                        ));
                        self::assertSame($selectedHashes[
                            $mode==='on'?'cta-digest-on':'cta-digest-off'
                        ],$digest[1]);
                        self::assertSame(1,preg_match(
                            '/\bsrcdoc="([^"]+)"/s',$outer,$match
                        ));
                        $inner=html_entity_decode(
                            $match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        self::assertSame(hash('sha256',$inner),$digest[1]);
                        self::assertStringContainsString(
                            '<meta name="robots" content="noindex, nofollow">',
                            $inner
                        );
                        self::assertSame($mode==='on'?2:1,
                            substr_count($inner,'href="#contato"'));
                        self::assertSame($mode==='on'?1:0,
                            substr_count($inner,'class="btn secondary-cta"'));
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        $byDevice[$device]=[$inner,$digest[1]];
                        ++$views;
                    }
                    self::assertSame($byDevice['desktop'],$byDevice['mobile']);
                    $sha[$mode]=$byDevice['desktop'][1];
                }
                self::assertNotSame($sha['on'],$sha['off']);
                self::assertSame(
                    $evidenceHashes[$product][$preset]['off']['desktop'],
                    $evidenceHashes[$product][$preset]['on']['mobile'],
                    'CTA-only evidence must bind the same immutable pair'
                );
            }
        }
        self::assertSame(24,$views);
    }

    public function test_invalid_selectors_public_network_and_mutating_routes_denied():void
    {
        foreach ([
            '',
            '?product=onepage',
            '?product=onepage&device=desktop&preset=classic',
            '?product=onepage&device=desktop&preset=classic&cta=',
            '?product=onepage&device=desktop&preset=classic&cta=maybe',
            '?product=onepage&device=desktop&preset=classic&cta[]=on',
            '?product[]=onepage&device=desktop&preset=classic&cta=on',
            '?product=onepage&device=desktop&preset=classic&cta=on&publish=true',
            '?product=onepage&device=tablet&preset=classic&cta=on',
            '?product=onepage&device=desktop&preset=unsafe&cta=on',
        ] as $q) $this->get(self::PATH.$q)->assertNotFound();

        $good=$this->url('onepage','desktop','classic','on');
        foreach (self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get($good)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.66'])
            ->get($good)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post($good)->assertStatus(405);
    }

    public function test_old_rich_review_does_not_inherit_cta_controls():void
    {
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        try {
            $legacy=$this->get('/_lab/sites/v1/rich-review')->assertOk();
            $html=(string)$legacy->getContent();
            self::assertStringNotContainsString('Comparar CTA secundário',$html);
            self::assertStringNotContainsString('cta-digest-off',$html);
        } finally {
            putenv('SITES_SYNTHETIC_RICH_REVIEW');
        }
    }
}
