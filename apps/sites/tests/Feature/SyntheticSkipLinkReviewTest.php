<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-285: strictly local, read-only Laravel keyboard skip link comparison. */
final class SyntheticSkipLinkReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/skip-link-review';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
        'SITES_F285_SKIP_REVIEW',
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

    private function uri(
        string $product,string $device,string $preset,string $skip
    ):string {
        return self::PATH.'?product='.$product.'&device='.$device.
            '&preset='.$preset.'&skip='.$skip;
    }

    public function test_all_24_private_skip_views_are_byte_pinned_and_nonpublishable():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                $versions=[];
                foreach(['off','on'] as $skip) {
                    $devices=[];
                    foreach(['desktop','mobile'] as $device) {
                        $response=$this->get($this->uri(
                            $product,$device,$preset,$skip
                        ))->assertOk();
                        $response->assertHeader('X-Frame-Options','DENY');
                        $response->assertHeader(
                            'X-Robots-Tag','noindex, nofollow, noarchive'
                        );
                        self::assertStringContainsString('no-store',
                            (string)$response->headers->get('Cache-Control'));
                        $csp=(string)$response->headers->get('Content-Security-Policy');
                        foreach(["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                            self::assertStringContainsString($rule,$csp);
                        }
                        $outer=(string)$response->getContent();
                        foreach([
                            'Comparar link de acessibilidade',
                            'Revisão interna pendente',
                            'sandbox=""',
                            'class="browser '.$device.'"',
                            'release-f285-'.$product.'-'.$preset.'-'.$skip,
                        ] as $required) {
                            self::assertStringContainsString($required,$outer);
                        }
                        self::assertStringNotContainsString('<script',$outer);
                        self::assertStringNotContainsString('<form',$outer);
                        foreach(['off','on'] as $option) {
                            self::assertStringContainsString(
                                'href="'.str_replace('&','&amp;',
                                    $this->uri($product,$device,$preset,$option)
                                ).'"',$outer
                            );
                        }

                        foreach([
                            'skip-digest-off','skip-digest-on',
                            'skip-package-digest','artifact-digest'
                        ] as $id) {
                            self::assertSame(1,preg_match(
                                '/id="'.preg_quote($id,'/').'">([a-f0-9]{64})<\/code>/',
                                $outer,$parts
                            ),$id);
                            $digests[$id]=$parts[1];
                        }
                        self::assertNotSame($digests['skip-digest-off'],
                            $digests['skip-digest-on']);
                        self::assertSame(
                            $skip==='on'?$digests['skip-digest-on']:
                                $digests['skip-digest-off'],
                            $digests['artifact-digest']
                        );
                        self::assertSame(1,preg_match(
                            '/\bsrcdoc="([^"]+)"/s',$outer,$matched
                        ));
                        $inner=html_entity_decode(
                            $matched[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        self::assertSame(
                            hash('sha256',$inner),$digests['artifact-digest']
                        );
                        self::assertStringContainsString(
                            $skip==='on'
                                ? '<main id="principal" tabindex="-1">'
                                : '<main id="principal">',
                            $inner
                        );
                        self::assertSame($skip==='on'?1:0,
                            substr_count($inner,
                                '<a class="skip-to-main" href="#principal">'));
                        if ($skip==='on') {
                            self::assertLessThan(
                                strpos($inner,'<header>'),
                                strpos($inner,'<a class="skip-to-main"')
                            );
                            self::assertStringContainsString(
                                '.skip-to-main:focus-visible',$inner
                            );
                        }
                        self::assertSame(2,substr_count($inner,'<details>'));
                        self::assertStringContainsString(
                            'noindex, nofollow',$inner
                        );
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertStringNotContainsString('https://',$inner);
                        $devices[$device]=[
                            $inner,$digests['artifact-digest'],
                            $digests['skip-package-digest'],
                        ];
                        ++$count;
                    }
                    self::assertSame($devices['desktop'],$devices['mobile']);
                    $versions[$skip]=$devices['desktop'];
                }
                self::assertNotSame($versions['off'][1],$versions['on'][1]);
                self::assertSame($versions['off'][2],$versions['on'][2],
                    'same two private packages belong to both options');
            }
        }
        self::assertSame(24,$count);
    }

    public function test_route_refuses_unknown_inputs_disabled_flags_and_remote_access():void
    {
        foreach([
            '',
            '?product=onepage',
            '?product=onepage&device=desktop&preset=classic',
            '?product=onepage&device=desktop&preset=classic&skip=other',
            '?product=onepage&device=desktop&preset=classic&skip=',
            '?product=onepage&device=desktop&preset=classic&skip[]=on',
            '?product=onepage&device=desktop&preset=classic&skip=on&faq=on',
            '?product=landing_page&device=tablet&preset=classic&skip=on',
            '?product[]=onepage&device=desktop&preset=classic&skip=on',
            '?product=onepage&device=desktop&preset=unsafe&skip=on',
            '?product=onepage&device=desktop&preset=classic&skip=on&publish=1',
        ] as $query) $this->get(self::PATH.$query)->assertNotFound();
        $uri=$this->uri('onepage','desktop','classic','on');
        foreach(self::FLAGS as $flag){
            putenv($flag);
            try{$this->get($uri)->assertNotFound();}
            finally{putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.99'])
            ->get($uri)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post($uri)->assertStatus(405);
    }

    public function test_other_reviewers_do_not_inherit_skip_controls():void
    {
        putenv('SITES_F272_FAQ_REVIEW=1');
        try {
            $other=$this->get(
                '/_lab/sites/v1/faq-disclosure-review'.
                '?product=onepage&device=desktop&preset=classic&faq=off'
            )->assertOk();
            self::assertStringNotContainsString(
                'Comparar link de acessibilidade',(string)$other->getContent()
            );
        } finally {putenv('SITES_F272_FAQ_REVIEW');}
    }
}
