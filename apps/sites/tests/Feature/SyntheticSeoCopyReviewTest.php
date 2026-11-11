<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-222: compare default versus reviewer-selected SEO copy using the actual
 * Laravel private iframe, with no real editor, URL or publication power.
 */
final class SyntheticSeoCopyReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/seo-copy-review';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW','SITES_F222_SEO_COPY_REVIEW',
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

    private function link(
        string $product,string $device,string $preset,
        string $seo,string $social
    ):string {
        return self::PATH.'?product='.$product.'&device='.$device.
            '&preset='.$preset.'&seo='.$seo.'&social='.$social;
    }

    public function test_all_48_previews_preserve_head_html_and_new_review_identity():void
    {
        $views=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['off','on'] as $social) {
                    $variants=[];
                    $evidenceByMode=[];
                    foreach(['default','custom'] as $seoMode) {
                        $byDevice=[];
                        $evidenceByDevice=[];
                        foreach(['desktop','mobile'] as $device) {
                            $res=$this->get($this->link(
                                $product,$device,$preset,$seoMode,$social
                            ))->assertOk();
                            $res->assertHeader('X-Frame-Options','DENY');
                            $res->assertHeader(
                                'X-Robots-Tag','noindex, nofollow, noarchive'
                            );
                            self::assertStringContainsString('no-store',
                                (string)$res->headers->get('Cache-Control'));
                            $csp=(string)$res->headers->get('Content-Security-Policy');
                            foreach(["script-src 'none'","connect-src 'none'",
                                "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                                self::assertStringContainsString($rule,$csp);
                            }
                            $outer=(string)$res->getContent();
                            foreach([
                                'Comparar texto SEO do site',
                                'Prévia textual de busca — não indexada',
                                'A manchete da página não muda',
                                'Metadados do título e descrição',
                                'Cartão de compartilhamento',
                                'SHA-256 da origem',
                                'SHA-256 da prévia selecionada',
                                'Evidência técnica da comparação SEO',
                                'Conferir integridade dos oito arquivos da revisão',
                                'A evidência é somente técnica',
                                'Aceite oficial pendente no Portal',
                                'Aprovação, publicação e entrega desabilitadas',
                                'sandbox=""','class="browser '.$device.'"',
                                'release-f222-'.$product.'-'.$preset.'-'.$seoMode.'-'.$social,
                            ] as $text)self::assertStringContainsString($text,$outer);
                            self::assertStringNotContainsString('<script',$outer);
                            self::assertStringNotContainsString('<form',$outer);
                            self::assertStringNotContainsString('https://',$outer);

                            foreach(['default','custom'] as $option) {
                                self::assertStringContainsString(
                                    'href="'.$this->link(
                                        $product,$device,$preset,$option,$social
                                    ).'"',
                                    str_replace('&amp;','&',$outer)
                                );
                            }
                            foreach(['off','on'] as $option) {
                                self::assertStringContainsString(
                                    'href="'.$this->link(
                                        $product,$device,$preset,$seoMode,$option
                                    ).'"',
                                    str_replace('&amp;','&',$outer)
                                );
                            }

                            self::assertSame(1,preg_match(
                                '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$main
                            ));
                            self::assertSame(1,preg_match(
                                '/id="seo-artifact-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$seoHash
                            ));
                            self::assertSame(1,preg_match(
                                '/id="seo-copy-original-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$previous
                            ));
                            self::assertSame(1,preg_match(
                                '/id="seo-copy-selected-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$selected
                            ));
                            self::assertSame($main[1],$seoHash[1]);
                            self::assertSame($main[1],$selected[1]);
                            self::assertSame(1,preg_match(
                                '/id="seo-change-evidence-digest">([a-f0-9]{64})<\\/code>/',
                                $outer,$evidence
                            ));
                            self::assertSame(1,preg_match(
                                '/id="seo-evidence-before">([a-f0-9]{64})<\\/code>/',
                                $outer,$proofBefore
                            ));
                            self::assertSame(1,preg_match(
                                '/id="seo-evidence-after">([a-f0-9]{64})<\\/code>/',
                                $outer,$proofAfter
                            ));
                            self::assertSame($previous[1],$proofBefore[1]);
                            self::assertSame(
                                $seoMode==='default'?$proofBefore[1]:$proofAfter[1],
                                $main[1]
                            );
                            self::assertNotSame($proofBefore[1],$proofAfter[1]);
                            self::assertSame(2,substr_count(
                                $outer,'internal-review-dossier.json:'
                            ));
                            self::assertSame(2,substr_count(
                                $outer,'release-manifest.json:'
                            ));
                            self::assertStringNotContainsString(
                                'tenant-f222',$outer
                            );
                            self::assertStringNotContainsString(
                                'site-f222',$outer
                            );
                            if($seoMode==='custom') {
                                self::assertNotSame($previous[1],$main[1]);
                                self::assertStringContainsString(
                                    'Conteúdo SEO modificado; nova revisão obrigatória.',
                                    $outer
                                );
                            }else{
                                self::assertSame($previous[1],$main[1]);
                                self::assertStringContainsString(
                                    'Metadados automáticos da versão de origem',
                                    $outer
                                );
                            }

                            self::assertSame(1,preg_match(
                                '/\bsrcdoc="([^"]+)"/s',$outer,$m
                            ));
                            $inner=html_entity_decode(
                                $m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                            );
                            self::assertSame($main[1],hash('sha256',$inner));
                            self::assertStringContainsString(
                                '<meta name="robots" content="noindex, nofollow">',
                                $inner
                            );
                            self::assertStringNotContainsString('rel="canonical"',$inner);
                            self::assertStringNotContainsString('og:url',$inner);
                            self::assertStringNotContainsString('og:image',$inner);
                            self::assertStringNotContainsString('<form',$inner);
                            self::assertStringNotContainsString('<script',$inner);
                            self::assertSame($social==='on'?12:5,
                                substr_count($inner,'<meta '));

                            if($seoMode==='custom') {
                                self::assertStringContainsString(
                                    '<title>Serviços de comunicação e tecnologia '.
                                    '| Imagine Demonstração</title>',$inner
                                );
                                self::assertStringContainsString(
                                    'metadados editoriais próprios',$inner
                                );
                            }else{
                                self::assertStringNotContainsString(
                                    '<title>Serviços de comunicação e tecnologia '.
                                    '| Imagine Demonstração</title>',$inner
                                );
                            }
                            $byDevice[$device]=[$main[1],$inner];
                            $evidenceByDevice[$device]=$evidence[1];
                            ++$views;
                        }
                        self::assertSame($byDevice['desktop'],$byDevice['mobile']);
                        self::assertSame(
                            $evidenceByDevice['desktop'],$evidenceByDevice['mobile']
                        );
                        $evidenceByMode[$seoMode]=$evidenceByDevice['desktop'];
                        $variants[$seoMode]=$byDevice['desktop'][0];
                    }
                    self::assertNotSame($variants['default'],$variants['custom'],
                        'Changing SEO meta copy produces a distinct reviewed HTML');
                    self::assertSame(
                        $evidenceByMode['default'],$evidenceByMode['custom'],
                        'Both views must display the same evidence for their exact pair'
                    );
                }
            }
        }
        self::assertSame(48,$views);
    }

    public function test_invalid_modes_flags_and_external_request_denied():void
    {
        foreach([
            '',
            '?product=onepage',
            '?seo=custom',
            '?social=on',
            '?seo[]=custom&social=on',
            '?seo=anything&social=on',
            '?seo=custom&social[]=on',
            '?seo=custom&social=remote',
            '?seo=custom&social=on&clientId=123',
            '?seo=custom&social=on&index=true',
            '?seo=custom&social=on&preset=vip',
            '?seo=custom&social=on&product=blog',
        ] as $query)$this->get(self::PATH.$query)->assertNotFound();
        $valid=self::PATH.'?seo=custom&social=on';
        foreach(self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get($valid)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.61'])
            ->get($valid)->assertNotFound();
        $this->post($valid)->assertStatus(405);
    }

    public function test_other_reviewers_do_not_inherit_custom_seo_controls():void
    {
        $old=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        self::assertStringNotContainsString('seo-copy-original-digest',
            (string)$old->getContent());
        self::assertStringNotContainsString('Comparar texto SEO do site',
            (string)$old->getContent());
        self::assertStringNotContainsString('seo-change-evidence-digest',
            (string)$old->getContent());
    }
}
