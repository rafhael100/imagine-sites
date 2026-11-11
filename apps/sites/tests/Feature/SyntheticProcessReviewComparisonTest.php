<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-170: version-4 process pages join existing private Laravel comparison. */
final class SyntheticProcessReviewComparisonTest extends TestCase
{
    private const PATH='/_lab/sites/v1/rich-compare';

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        putenv('SITES_SYNTHETIC_RICH_COMPARE=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach([
            'SITES_SYNTHETIC_RICH_COMPARE',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_v4_process_variants_are_real_immutable_html_in_sandbox():void
    {
        $seen=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['steps','full'] as $variant) {
                    $digests=[];
                    foreach(['desktop','mobile'] as $device) {
                        $response=$this->get(self::PATH.
                            '?product='.$product.'&device='.$device.
                            '&preset='.$preset.'&variant='.$variant)->assertOk();
                        $response->assertHeader('X-Frame-Options','DENY');
                        $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                        self::assertStringContainsString('no-store',
                            (string)$response->headers->get('Cache-Control'));
                        $csp=(string)$response->headers->get('Content-Security-Policy');
                        foreach(["script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"] as $part) {
                            self::assertStringContainsString($part,$csp);
                        }

                        $html=(string)$response->getContent();
                        self::assertStringContainsString('Comparar blocos do site',$html);
                        self::assertStringContainsString('Prévia v4 totalmente fictícia',$html);
                        self::assertStringContainsString('3 etapas de trabalho incluídas',$html);
                        self::assertStringContainsString('Serviços e etapas',$html);
                        self::assertStringContainsString('Página completa',$html);
                        self::assertStringContainsString('Aceite oficial pendente no Portal',$html);
                        self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$html);
                        self::assertStringContainsString('sandbox=""',$html);
                        self::assertStringContainsString('class="browser '.$device.'"',$html);
                        self::assertStringContainsString(
                            'release-f164-'.$product.'-'.$preset.'-'.$variant,$html
                        );
                        foreach(['faq','highlights','both','steps','full'] as $option) {
                            self::assertStringContainsString(
                                'href="'.self::PATH.'?product='.$product.
                                '&amp;device='.$device.'&amp;preset='.$preset.
                                '&amp;variant='.$option.'"',$html
                            );
                        }
                        self::assertStringNotContainsString('<script',$html);
                        self::assertStringNotContainsString('<form',$html);
                        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$html,$m));
                        $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                        self::assertSame(1,substr_count($inner,'<section class="process"'));
                        self::assertSame(1,substr_count($inner,'<ol>'));
                        self::assertSame(1,preg_match(
                            '~<section class="process".*?</section>~s',$inner,$section
                        ));
                        self::assertSame(3,substr_count($section[0],'<li><h3>'),
                            'Exactly three ordered process steps');
                        self::assertStringContainsString('<h3>Briefing e planejamento</h3>',$inner);
                        self::assertStringContainsString('<h3>Criação do rascunho</h3>',$inner);
                        self::assertStringContainsString('<h3>Revisão e aceite</h3>',$inner);
                        self::assertSame(1,substr_count($inner,'<section class="highlights"'));
                        self::assertSame($variant==='steps'?0:1,
                            substr_count($inner,'<section class="faq"'));
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertStringContainsString('noindex, nofollow',$inner);
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$html,$hash
                        ));
                        self::assertSame(hash('sha256',$inner),$hash[1]);
                        $digests[$device]=$hash[1];
                    }
                    self::assertSame($digests['desktop'],$digests['mobile']);
                    $seen[]=$digests['desktop'];
                }
            }
        }
        self::assertCount(12,array_unique($seen),
            'Each product/preset/process combination requires its own review bytes');
    }

    public function test_v3_variants_and_legacy_route_never_acquire_v4_process():void
    {
        foreach(['faq','highlights','both'] as $variant) {
            $response=$this->get(self::PATH.'?variant='.$variant)->assertOk();
            $html=(string)$response->getContent();
            self::assertStringContainsString('Prévia v3 totalmente fictícia',$html);
            self::assertStringNotContainsString('3 etapas de trabalho incluídas',$html);
            self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$html,$m));
            $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
            self::assertStringNotContainsString('<section class="process"',$inner);
        }
        $legacy=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        self::assertStringNotContainsString('Comparar blocos do site',
            (string)$legacy->getContent());
        self::assertStringNotContainsString('3 etapas de trabalho incluídas',
            (string)$legacy->getContent());
    }

    public function test_v4_variants_deny_bad_options_and_inactive_flags():void
    {
        foreach([
            '?variant=process','?variant=all',
            '?variant[]=full','?variant=full&employeeId=12',
            '?variant=steps&product[]=onepage',
            '?variant=full&preset=vip',
        ] as $query) {
            $this->get(self::PATH.$query)->assertNotFound();
        }
        putenv('SITES_SYNTHETIC_RICH_COMPARE');
        try {$this->get(self::PATH.'?variant=full')->assertNotFound();}
        finally {putenv('SITES_SYNTHETIC_RICH_COMPARE=1');}
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.25'])
            ->get(self::PATH.'?variant=steps')->assertNotFound();
        $this->post(self::PATH.'?variant=full')->assertStatus(405);
    }
}
