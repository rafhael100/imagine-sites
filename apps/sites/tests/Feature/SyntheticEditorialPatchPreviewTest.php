<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-172: new in-process editorial patch review with before/after SHA. */
final class SyntheticEditorialPatchPreviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/editorial-review';

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        putenv('SITES_SYNTHETIC_RICH_REVIEW=1');
        putenv('SITES_SYNTHETIC_EDITORIAL_REVIEW=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        foreach([
            'SITES_SYNTHETIC_EDITORIAL_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_changed_copy_uses_new_digest_for_six_products_and_styles():void
    {
        $all=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                $digests=[];
                foreach(['desktop','mobile'] as $device) {
                    $response=$this->get(self::PATH.
                        '?product='.$product.'&device='.$device.'&preset='.$preset
                    )->assertOk();
                    $response->assertHeader('X-Frame-Options','DENY');
                    $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                    self::assertStringContainsString('no-store',
                        (string)$response->headers->get('Cache-Control'));
                    $csp=(string)$response->headers->get('Content-Security-Policy');
                    foreach(["script-src 'none'","connect-src 'none'",
                        "form-action 'none'","frame-ancestors 'none'"] as $part) {
                        self::assertStringContainsString($part,$csp);
                    }
                    $outer=(string)$response->getContent();
                    foreach([
                        'Comparação da edição','faq, processSteps',
                        'A edição não reaproveita o aceite anterior',
                        'Aceite oficial pendente no Portal',
                        'Aprovação, publicação e entrega desabilitadas',
                        'sandbox=""',
                        'class="browser '.$device.'"',
                        'release-f172-after-'.$product.'-'.$preset,
                    ] as $text) {
                        self::assertStringContainsString($text,$outer);
                    }
                    self::assertStringNotContainsString('<script',$outer);
                    self::assertStringNotContainsString('<form',$outer);
                    self::assertSame(1,preg_match(
                        '/id="previous-artifact-digest">([a-f0-9]{64})<\/code>/',$outer,$previous
                    ));
                    self::assertSame(1,preg_match(
                        '/id="next-artifact-digest">([a-f0-9]{64})<\/code>/',$outer,$next
                    ));
                    self::assertSame(1,preg_match(
                        '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$outer,$shown
                    ));
                    self::assertNotSame($previous[1],$next[1]);
                    self::assertSame($next[1],$shown[1]);
                    self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$m));
                    $inner=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                    self::assertSame(hash('sha256',$inner),$next[1]);
                    self::assertStringContainsString(
                        'O texto demonstrativo foi editado pela equipe Imagine',$inner
                    );
                    self::assertStringContainsString(
                        'Uma alteração editorial fictícia gera outra versão',$inner
                    );
                    foreach(['process','faq','highlights'] as $section) {
                        self::assertSame(1,substr_count($inner,
                            '<section class="'.$section.'"'));
                    }
                    self::assertStringNotContainsString('<script',$inner);
                    self::assertStringNotContainsString('<form',$inner);
                    $digests[$device]=[$previous[1],$next[1],$inner];
                }
                self::assertSame($digests['desktop'],$digests['mobile']);
                $all[]=$digests['desktop'][1];
            }
        }
        self::assertCount(6,array_unique($all));
    }

    public function test_invalid_query_and_missing_flags_fail_closed():void
    {
        foreach([
            '?product=bogus','?product[]=onepage',
            '?device=tablet','?preset[]=classic',
            '?clientId=123','?changes[headline]=forged',
            '?publishable=true',
        ] as $query) $this->get(self::PATH.$query)->assertNotFound();
        foreach([
            'SITES_SYNTHETIC_EDITORIAL_REVIEW',
            'SITES_SYNTHETIC_RICH_REVIEW',
            'SITES_SYNTHETIC_REVIEW_UI',
            'SITES_SYNTHETIC_HTTP',
        ] as $flag) {
            putenv($flag);
            try {$this->get(self::PATH)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.19'])
            ->get(self::PATH)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post(self::PATH)->assertStatus(405);
    }

    public function test_previous_reviewer_is_not_a_patch_editor():void
    {
        $old=$this->get('/_lab/sites/v1/rich-review')->assertOk();
        self::assertStringNotContainsString('Comparação da edição',
            (string)$old->getContent());
        self::assertStringNotContainsString('previous-artifact-digest',
            (string)$old->getContent());
    }
}
