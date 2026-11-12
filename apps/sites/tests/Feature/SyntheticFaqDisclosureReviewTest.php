<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-272: readonly private reviewer of native FAQ versus traditional FAQ. */
final class SyntheticFaqDisclosureReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/faq-disclosure-review';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
        'SITES_F272_FAQ_REVIEW',
    ];

    protected function setUp():void
    {
        foreach(self::FLAGS as $flag)putenv($flag.'=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach(self::FLAGS as $flag)putenv($flag);
        parent::tearDown();
    }

    private function url(
        string $product,string $device,string $preset,string $faq
    ):string {
        return self::PATH.'?product='.$product.'&device='.$device.
            '&preset='.$preset.'&faq='.$faq;
    }

    public function test_24_private_views_have_two_distinct_digest_pinned_faq_modes():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                $hashes=[];
                $evidences=[];
                foreach(['off','on'] as $faq) {
                    $devices=[];
                    foreach(['desktop','mobile'] as $device) {
                        $res=$this->get($this->url(
                            $product,$device,$preset,$faq
                        ))->assertOk();
                        $res->assertHeader('X-Frame-Options','DENY');
                        $res->assertHeader('X-Robots-Tag',
                            'noindex, nofollow, noarchive');
                        self::assertStringContainsString('no-store',
                            (string)$res->headers->get('Cache-Control'));
                        $csp=(string)$res->headers->get('Content-Security-Policy');
                        foreach(["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                            self::assertStringContainsString($rule,$csp);
                        }
                        $outer=(string)$res->getContent();
                        foreach([
                            'Comparar FAQ expansível',
                            'Revisão interna pendente',
                            'Aceite oficial pendente no Portal',
                            'Aprovação, publicação e entrega desabilitadas',
                            'sandbox=""',
                            'class="browser '.$device.'"',
                            'release-f272-'.$product.'-'.$preset.'-'.$faq,
                        ] as $phrase) {
                            self::assertStringContainsString($phrase,$outer);
                        }
                        self::assertStringNotContainsString('<script',$outer);
                        self::assertStringNotContainsString('<form',$outer);

                        foreach(['off','on'] as $option) {
                            self::assertStringContainsString(
                                'href="'.str_replace('&','&amp;',
                                    $this->url($product,$device,$preset,$option)
                                ).'"',$outer
                            );
                        }

                        self::assertSame(1,preg_match(
                            '/id="faq-digest-off">([a-f0-9]{64})<\/code>/',
                            $outer,$offProof
                        ));
                        self::assertSame(1,preg_match(
                            '/id="faq-digest-on">([a-f0-9]{64})<\/code>/',
                            $outer,$onProof
                        ));
                        self::assertNotSame($offProof[1],$onProof[1]);
                        self::assertSame(1,preg_match(
                            '/id="faq-package-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$pair
                        ));
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$shown
                        ));
                        self::assertSame(
                            $faq==='on'?$onProof[1]:$offProof[1],
                            $shown[1]
                        );
                        self::assertSame(1,preg_match(
                            '/\bsrcdoc="([^"]+)"/s',$outer,$match
                        ));
                        $inner=html_entity_decode(
                            $match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        self::assertSame(hash('sha256',$inner),$shown[1]);
                        self::assertStringContainsString(
                            '<meta name="robots" content="noindex, nofollow">',
                            $inner
                        );
                        self::assertSame($faq==='on'?2:0,
                            substr_count($inner,'<details>'));
                        self::assertSame($faq==='on'?2:0,
                            substr_count($inner,'<summary>'));
                        self::assertSame($faq==='on'?0:2,
                            substr_count($inner,'<h3>'));
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertStringNotContainsString('https://',$inner);
                        $devices[$device]=[$inner,$shown[1],$pair[1]];
                        ++$count;
                    }
                    self::assertSame($devices['desktop'],$devices['mobile']);
                    $hashes[$faq]=$devices['desktop'][1];
                    $evidences[$faq]=$devices['desktop'][2];
                }
                self::assertNotSame($hashes['off'],$hashes['on']);
                self::assertSame($evidences['off'],$evidences['on'],
                    'same two verified four-file packages bind both modes');
            }
        }
        self::assertSame(24,$count);
    }

    public function test_url_flags_public_access_and_mutations_denied():void
    {
        foreach([
            '',
            '?product=onepage',
            '?product=onepage&device=desktop&preset=classic',
            '?product=onepage&device=desktop&preset=classic&faq=maybe',
            '?product=onepage&device=desktop&preset=classic&faq=',
            '?product=onepage&device=desktop&preset=classic&faq[]=on',
            '?product=onepage&device=desktop&preset=classic&faq=on&publish=1',
            '?product=onepage&device=desktop&preset=classic&faq=on&cta=on',
            '?product[]=onepage&device=desktop&preset=classic&faq=on',
            '?product=landing_page&device=tablet&preset=classic&faq=on',
            '?product=onepage&device=desktop&preset=unsafe&faq=on',
        ] as $query) $this->get(self::PATH.$query)->assertNotFound();

        $valid=$this->url('onepage','desktop','classic','on');
        foreach(self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get($valid)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.99'])
            ->get($valid)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post($valid)->assertStatus(405);
    }

    public function test_old_reviewer_never_inherits_faq_controls():void
    {
        putenv('SITES_F254_SECONDARY_CTA_REVIEW=1');
        try {
            $existing=$this->get(
                '/_lab/sites/v1/secondary-cta-review'.
                '?product=onepage&device=desktop&preset=classic&cta=off'
            )->assertOk();
            self::assertStringNotContainsString(
                'Comparar FAQ expansível',(string)$existing->getContent()
            );
        } finally {putenv('SITES_F254_SECONDARY_CTA_REVIEW');}
    }
}
