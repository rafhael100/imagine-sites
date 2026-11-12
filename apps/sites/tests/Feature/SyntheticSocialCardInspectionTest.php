<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-210: Laravel preview must mirror the embedded OG bytes, not fetch a network card. */
final class SyntheticSocialCardInspectionTest extends TestCase
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
        foreach(self::FLAGS as $key)putenv($key.'=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach(self::FLAGS as $key)putenv($key);
        parent::tearDown();
    }

    public function test_the_text_card_matches_exact_real_og_metadata_across_devices():void
    {
        $copy=json_decode(
            (string)file_get_contents(base_path(
                '../../fixtures/sites/f1-170-process-copy.synthetic.json'
            )),true,16,JSON_THROW_ON_ERROR
        );
        $expectedTitle=$copy['headline'].' | Marca Fictícia Imagine';
        $expectedDesc=$copy['description'];

        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                $digests=[];
                foreach(['desktop','mobile'] as $device) {
                    $response=$this->get(self::PATH.'?product='.$product.
                        '&device='.$device.'&preset='.$preset.'&social=on'
                    )->assertOk();
                    $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                    $response->assertHeader('X-Frame-Options','DENY');
                    self::assertStringContainsString('no-store',
                        (string)$response->headers->get('Cache-Control'));
                    self::assertStringContainsString("script-src 'none'",
                        (string)$response->headers->get('Content-Security-Policy'));
                    $page=(string)$response->getContent();
                    self::assertStringContainsString(
                        'Simulação textual do cartão de compartilhamento',$page
                    );
                    self::assertStringContainsString(
                        'id="social-card-heading">'.htmlspecialchars($expectedTitle,
                            ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8').'</h3>',$page
                    );
                    self::assertStringContainsString(
                        'id="social-card-description">'.htmlspecialchars($expectedDesc,
                            ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8').'</p>',$page
                    );
                    self::assertStringContainsString('sem URL e sem imagem',$page);
                    self::assertStringContainsString('sandbox=""',$page);
                    self::assertStringNotContainsString('<form',$page);
                    self::assertStringNotContainsString('<script',$page);
                    self::assertStringNotContainsString('social-card-disabled',$page);
                    self::assertSame(1,preg_match(
                        '/id="social-card-digest">([a-f0-9]{64})<\/code>/',
                        $page,$cardDigest
                    ));
                    self::assertSame(1,preg_match(
                        '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                        $page,$pageDigest
                    ));
                    self::assertSame($pageDigest[1],$cardDigest[1]);
                    self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',
                        $page,$m));
                    $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                    self::assertSame(hash('sha256',$inner),$cardDigest[1]);
                    self::assertStringContainsString(
                        '<meta property="og:title" content="'.htmlspecialchars(
                            $expectedTitle,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8').'">',
                        $inner
                    );
                    self::assertStringContainsString(
                        '<meta name="twitter:description" content="'.htmlspecialchars(
                            $expectedDesc,ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5,'UTF-8').'">',
                        $inner
                    );
                    foreach(['og:image','og:url','twitter:image','<form','<script'] as $bad)
                        self::assertStringNotContainsString($bad,$inner);
                    $digests[$device]=$cardDigest[1];
                }
                self::assertSame($digests['desktop'],$digests['mobile']);
            }
        }
    }

    public function test_disabled_preview_has_no_simulated_social_identity():void
    {
        $response=$this->get(self::PATH.'?social=off')->assertOk();
        $html=(string)$response->getContent();
        self::assertStringContainsString('id="social-card-disabled"',$html);
        self::assertStringNotContainsString('id="social-card-heading"',$html);
        self::assertStringNotContainsString('id="social-card-digest"',$html);
        self::assertStringNotContainsString('social-card-preview{',$html);
    }

    public function test_unrelated_reviewers_do_not_inherit_simulated_card():void
    {
        $html=(string)$this->get('/_lab/sites/v1/rich-review')
            ->assertOk()->getContent();
        self::assertStringNotContainsString('social-card-heading',$html);
        self::assertStringNotContainsString('social-card-preview{',$html);
    }
}
