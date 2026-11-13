<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-164: compare three strictly fictional page compositions in Laravel CLI. */
final class SyntheticRichReviewComparisonTest extends TestCase
{
    private const PATH='/_lab/sites/v1/rich-compare';

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        putenv('SITES_SYNTHETIC_RICH_COMPARE=1');
        putenv('SITES_SYNTHETIC_EXPORT');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach([
            'SITES_SYNTHETIC_RICH_COMPARE',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
            'SITES_SYNTHETIC_EXPORT',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    /** @return array{string,string,string} outer, exact inner, sha */
    private function page(string $product,string $style,string $device,string $variant):array
    {
        $query='?product='.$product.'&device='.$device.'&preset='.$style.'&variant='.$variant;
        $response=$this->get(self::PATH.$query)->assertOk();
        $response->assertHeader('X-Frame-Options','DENY');
        $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
        $response->assertHeader('Referrer-Policy','no-referrer');
        $csp=(string)$response->headers->get('Content-Security-Policy');
        foreach(["script-src 'none'","connect-src 'none'","form-action 'none'",
            "frame-ancestors 'none'"] as $token) {
            self::assertStringContainsString($token,$csp);
        }
        self::assertStringContainsString('no-store',
            (string)$response->headers->get('Cache-Control'));
        $html=(string)$response->getContent();
        self::assertStringContainsString('Comparar blocos do site',$html);
        self::assertStringContainsString('Conteúdo da página ampliado',$html);
        self::assertStringContainsString('Aceite oficial pendente no Portal',$html);
        self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$html);
        self::assertStringContainsString('sandbox=""',$html);
        self::assertStringContainsString('tabindex="0"',$html);
        self::assertStringContainsString('class="browser '.$device.'"',$html);
        self::assertStringContainsString('release-f164-'.$product.'-'.$style.'-'.$variant,$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringContainsString(
            'href="'.self::PATH.'?product='.$product.'&amp;device='.$device.
            '&amp;preset='.$style.'&amp;variant='.$variant.'"',
            $html
        );
        foreach(['faq','highlights','both'] as $option) {
            self::assertStringContainsString(
                'href="'.self::PATH.'?product='.$product.'&amp;device='.$device.
                '&amp;preset='.$style.'&amp;variant='.$option.'"',
                $html
            );
        }
        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$html,$m));
        $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        self::assertStringContainsString('noindex, nofollow',$inner);
        self::assertStringNotContainsString('<script',$inner);
        self::assertStringNotContainsString('<form',$inner);
        self::assertSame(1,preg_match('/id="artifact-digest">([a-f0-9]{64})<\/code>/',
            $html,$digest));
        self::assertSame(hash('sha256',$inner),$digest[1]);
        $expectedFaq=$variant==='highlights'?0:1;
        $expectedHighlights=$variant==='faq'?0:1;
        self::assertSame($expectedFaq,substr_count($inner,'<section class="faq"'));
        self::assertSame($expectedHighlights,substr_count($inner,'<section class="highlights"'));
        self::assertStringContainsString(
            $expectedFaq===1?'2 perguntas frequentes':'0 perguntas frequentes',$html
        );
        self::assertStringContainsString(
            $expectedHighlights===1?'2 soluções':'0 soluções',$html
        );
        return [$html,$inner,$digest[1]];
    }

    public function test_all_variants_are_distinct_and_device_independent():void
    {
        $digests=[];
        $allDigests=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $style) {
                foreach(['faq','highlights','both'] as $variant) {
                    [$desktop,$html,$sha]=$this->page($product,$style,'desktop',$variant);
                    [$mobile,$mobileHtml,$mobileSha]=$this->page(
                        $product,$style,'mobile',$variant
                    );
                    self::assertSame($html,$mobileHtml);
                    self::assertSame($sha,$mobileSha);
                    self::assertStringContainsString('class="browser desktop"',$desktop);
                    self::assertStringContainsString('class="browser mobile"',$mobile);
                    $digests[$product][$style][$variant]=$sha;
                    $allDigests[]=$sha;
                }
                self::assertCount(3,array_unique(
                    array_values($digests[$product][$style])
                ),'FAQ, highlights and both must produce separate reviewed bytes');
            }
        }
        self::assertCount(18,array_unique($allDigests));
    }

    public function test_variant_requires_explicit_allowlisted_selector():void
    {
        foreach([
            '',
            '?product=onepage',
            '?variant=',
            '?variant=unknown',
            '?variant[]=faq',
            '?product[]=onepage&variant=both',
            '?device=tablet&variant=both',
            '?preset=unknown&variant=both',
            '?variant=both&clientId=123',
            '?variant=both&publishable=true',
        ] as $query) {
            $this->get(self::PATH.$query)->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.25'])
            ->get(self::PATH.'?variant=both')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post(self::PATH.'?variant=both')->assertStatus(405);
        $this->post('/api/sites/publish')->assertNotFound();
    }

    public function test_all_laboratory_flags_required():void
    {
        foreach([
            'SITES_SYNTHETIC_RICH_COMPARE',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag) {
            putenv($flag);
            try {$this->get(self::PATH.'?variant=both')->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
    }

    public function test_existing_rich_review_remains_on_legacy_route_without_variant():void
    {
        $res=$this->get('/_lab/sites/v1/rich-review?product=onepage&device=mobile')
            ->assertOk();
        $html=(string)$res->getContent();
        self::assertStringNotContainsString('Comparar blocos do site',$html);
        self::assertStringContainsString('2 soluções e 2 perguntas frequentes',$html);
        self::assertStringNotContainsString('&amp;variant=',$html);
        self::assertStringContainsString('release-f161-onepage-classic',$html);
    }
}
