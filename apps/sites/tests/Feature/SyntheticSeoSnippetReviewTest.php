<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-216: HTML head, inner iframe and editorial SEO preview share the
 * EXACT reviewed SHA-256; it is never a public search result or URL.
 */
final class SyntheticSeoSnippetReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/composite-social';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP',
        'SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW',
        'SITES_F212_COMPOSITE_SOCIAL_REVIEW',
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

    private function getPage(
        string $product,string $preset,string $device,string $social
    ) {
        return $this->get(self::PATH.'?product='.$product.
            '&device='.$device.'&preset='.$preset.
            '&order=services_process_faq&nav=off&palette=default&social='.$social);
    }

    public function test_canonical_seo_preview_matches_noindex_iframe_in_24_reviews():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['off','on'] as $social) {
                    $byDevice=[];
                    foreach(['desktop','mobile'] as $device) {
                        $response=$this->getPage($product,$preset,$device,$social)
                            ->assertOk();
                        $response->assertHeader(
                            'X-Robots-Tag','noindex, nofollow, noarchive'
                        );
                        $response->assertHeader('X-Frame-Options','DENY');
                        self::assertStringContainsString('no-store',
                            (string)$response->headers->get('Cache-Control')
                        );
                        $csp=(string)$response->headers->get('Content-Security-Policy');
                        foreach([
                            "script-src 'none'","connect-src 'none'",
                            "form-action 'none'","frame-ancestors 'none'"
                        ] as $directive) {
                            self::assertStringContainsString($directive,$csp);
                        }

                        $outer=(string)$response->getContent();
                        foreach([
                            'Prévia textual de busca — não indexada',
                            'Nenhum buscador foi consultado',
                            'Título:',
                            'Descrição:',
                            'não tem URL pública',
                            'Aceite oficial pendente no Portal',
                            'Aprovação, publicação e entrega desabilitadas',
                            'sandbox=""',
                            'class="browser '.$device.'"',
                            'id="seo-preview-title"',
                            'id="seo-preview-description"',
                            'id="seo-title-length"',
                            'id="seo-description-length"',
                        ] as $label) {
                            self::assertStringContainsString($label,$outer);
                        }
                        self::assertStringNotContainsString('<form',$outer);
                        self::assertStringNotContainsString('<script',$outer);

                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$current
                        ));
                        self::assertSame(1,preg_match(
                            '/id="seo-artifact-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$seo
                        ));
                        self::assertSame($current[1],$seo[1]);
                        self::assertSame(1,preg_match(
                            '/\bsrcdoc="([^"]+)"/s',$outer,$html
                        ));
                        $inner=html_entity_decode(
                            $html[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        self::assertSame($current[1],hash('sha256',$inner));
                        self::assertStringContainsString(
                            '<meta name="robots" content="noindex, nofollow">',
                            $inner
                        );
                        self::assertStringNotContainsString('rel="canonical"',$inner);
                        self::assertStringNotContainsString('og:url',$inner);
                        self::assertStringNotContainsString('og:image',$inner);
                        self::assertStringNotContainsString('<form',$inner);
                        self::assertStringNotContainsString('<script',$inner);
                        self::assertSame(
                            $social==='on'?12:5,substr_count($inner,'<meta ')
                        );

                        $decodedOuter=html_entity_decode(
                            $outer,ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        self::assertSame(1,preg_match(
                            '/id="seo-preview-title">([^<]+)<\/p>/u',
                            $decodedOuter,$seoTitle
                        ));
                        self::assertSame(1,preg_match(
                            '/id="seo-preview-description">([^<]+)<\/p>/u',
                            $decodedOuter,$seoDescription
                        ));
                        self::assertSame(1,preg_match(
                            '/<title>([^<]+)<\/title>/u',$inner,$htmlTitle
                        ));
                        self::assertSame(1,preg_match(
                            '/<meta name="description" content="([^"]+)">/u',
                            $inner,$htmlDescription
                        ));
                        $realTitle=html_entity_decode(
                            $htmlTitle[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        $realDescription=html_entity_decode(
                            $htmlDescription[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                        );
                        $shorten=static fn(string $text,int $limit):string=>
                            mb_strlen($text,'UTF-8')<=$limit
                                ?$text:rtrim(mb_substr($text,0,$limit-1,'UTF-8')).'…';
                        self::assertSame($shorten($realTitle,60),$seoTitle[1]);
                        self::assertSame(
                            $shorten($realDescription,160),$seoDescription[1]
                        );
                        self::assertStringContainsString(
                            'id="seo-title-length">'.
                            mb_strlen($realTitle,'UTF-8').'</strong>',
                            $outer
                        );
                        self::assertStringContainsString(
                            'id="seo-description-length">'.
                            mb_strlen($realDescription,'UTF-8').'</strong>',
                            $outer
                        );
                        $byDevice[$device]=[
                            $current[1],$seoTitle[1],$seoDescription[1]
                        ];
                        ++$count;
                    }
                    self::assertSame(
                        $byDevice['desktop'],$byDevice['mobile'],
                        'Same design has same SEO content on both devices'
                    );
                }
            }
        }
        self::assertSame(24,$count);
    }

    public function test_missing_private_review_flag_rejects_seo_preview():void
    {
        $url=self::PATH.'?product=onepage&device=desktop&preset=classic'.
            '&order=services_process_faq&nav=off&palette=default&social=on';
        foreach(self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get($url)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->get($url.'&index=true')->assertNotFound();
        $this->get($url.'&canonical=https://example.com')->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.68'])
            ->get($url)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post($url)->assertStatus(405);
    }

    public function test_other_reviewer_routes_do_not_inherit_seo_preview():void
    {
        $old=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        self::assertStringNotContainsString('seo-artifact-digest',
            (string)$old->getContent());
        self::assertStringNotContainsString('Prévia textual de busca',
            (string)$old->getContent());
    }
}
