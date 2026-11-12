<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-279: private demo-only comparison of opt-in skip-to-main navigation. */
final class SyntheticSkipNavigationReviewTest extends TestCase
{
    private const ROUTE='/_lab/sites/v1/skip-navigation-review';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP',
        'SITES_SYNTHETIC_REVIEW_UI',
        'SITES_F279_SKIP_REVIEW',
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

    private function link(
        string $product,string $device,string $preset,string $mode
    ):string {
        return self::ROUTE.'?product='.$product.'&device='.$device.
            '&preset='.$preset.'&skip='.$mode;
    }

    public function test_24_local_comparisons_are_private_and_have_exact_digest():void
    {
        $viewCount=0;
        foreach(['onepage','landing_page'] as $product){
            foreach(['classic','modern','warm'] as $preset){
                $digests=[];
                $pairs=[];
                foreach(['off','on'] as $mode){
                    $devices=[];
                    foreach(['desktop','mobile'] as $device){
                        $r=$this->get($this->link($product,$device,$preset,$mode))
                            ->assertOk();
                        $r->assertHeader('X-Frame-Options','DENY');
                        $r->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                        self::assertStringContainsString('no-store',
                            (string)$r->headers->get('Cache-Control'));
                        $csp=(string)$r->headers->get('Content-Security-Policy');
                        foreach(["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                            self::assertStringContainsString($rule,$csp);
                        }
                        $outer=(string)$r->getContent();
                        foreach([
                            'Comparar acesso direto ao conteúdo',
                            'Revisão interna pendente',
                            'sandbox=""',
                            'class="browser '.$device.'"',
                            'release-f279-'.$product.'-'.$preset.'-'.$mode,
                        ] as $phrase) {
                            self::assertStringContainsString($phrase,$outer);
                        }
                        self::assertStringNotContainsString('<script',$outer);
                        self::assertStringNotContainsString('<form',$outer);
                        foreach(['off','on'] as $option) {
                            self::assertStringContainsString(
                                'href="'.str_replace('&','&amp;',
                                    $this->link($product,$device,$preset,$option)
                                ).'"',$outer
                            );
                        }
                        self::assertSame(1,preg_match(
                            '/id="skip-digest-off">([0-9a-f]{64})<\/code>/',
                            $outer,$offDigest
                        ));
                        self::assertSame(1,preg_match(
                            '/id="skip-digest-on">([0-9a-f]{64})<\/code>/',
                            $outer,$onDigest
                        ));
                        self::assertNotSame($offDigest[1],$onDigest[1]);
                        self::assertSame(1,preg_match(
                            '/id="skip-comparison-digest">([0-9a-f]{64})<\/code>/',
                            $outer,$pair
                        ));
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([0-9a-f]{64})<\/code>/',
                            $outer,$shown
                        ));
                        self::assertSame(
                            $mode==='on'?$onDigest[1]:$offDigest[1],
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
                        $skip='<a class="skip-content" href="#principal">Pular para o conteúdo</a>';
                        self::assertSame($mode==='on'?1:0,
                            substr_count($inner,$skip));
                        self::assertStringContainsString(
                            $mode==='on'
                              ? '<main id="principal" tabindex="-1">'
                              : '<main id="principal">',
                            $inner
                        );
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertStringNotContainsString('https://',$inner);
                        $devices[$device]=[$inner,$shown[1],$pair[1]];
                        ++$viewCount;
                    }
                    self::assertSame($devices['desktop'],$devices['mobile']);
                    $digests[$mode]=$devices['desktop'][1];
                    $pairs[$mode]=$devices['desktop'][2];
                }
                self::assertNotSame($digests['off'],$digests['on']);
                self::assertSame($pairs['off'],$pairs['on']);
            }
        }
        self::assertSame(24,$viewCount);
    }

    public function test_unknown_inputs_disabled_flags_and_public_addresses_denied():void
    {
        foreach([
            '',
            '?product=onepage',
            '?product=onepage&device=desktop&preset=classic',
            '?product=onepage&device=desktop&preset=classic&skip=',
            '?product=onepage&device=desktop&preset=classic&skip=auto',
            '?product=onepage&device=desktop&preset=classic&skip[]=on',
            '?product[]=onepage&device=desktop&preset=classic&skip=on',
            '?product=landing_page&device=tablet&preset=classic&skip=on',
            '?product=onepage&device=desktop&preset=raw&skip=on',
            '?product=onepage&device=desktop&preset=classic&skip=on&publish=true',
            '?product=onepage&device=desktop&preset=classic&skip=on&faq=on',
        ] as $query) {
            $this->get(self::ROUTE.$query)->assertNotFound();
        }
        $valid=$this->link('onepage','desktop','classic','on');
        foreach(self::FLAGS as $flag){
            putenv($flag);
            try{$this->get($valid)->assertNotFound();}
            finally{putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.55'])
            ->get($valid)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post($valid)->assertStatus(405);
    }

    public function test_other_review_routes_do_not_inherit_skip_controls():void
    {
        putenv('SITES_F272_FAQ_REVIEW=1');
        try {
            $html=(string)$this->get(
                '/_lab/sites/v1/faq-disclosure-review'.
                '?product=onepage&device=desktop&preset=classic&faq=off'
            )->assertOk()->getContent();
            self::assertStringNotContainsString(
                'Comparar acesso direto ao conteúdo',$html
            );
        }finally{putenv('SITES_F272_FAQ_REVIEW');}
    }
}
