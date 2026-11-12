<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticVisualPresetCatalog as Catalog;
use Tests\TestCase;

/**
 * F1-261: real Laravel renderer of allowlisted editor CTA brand palettes.
 * Strictly local CLI/test routes, no production contact data or live release.
 */
final class SyntheticBrandedCtaReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/secondary-cta-review';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
        'SITES_F254_SECONDARY_CTA_REVIEW',
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
        string $product,string $device,string $preset,
        string $cta,string $palette
    ):string {
        return self::PATH.'?product='.$product.'&device='.$device.
            '&preset='.$preset.'&cta='.$cta.'&palette='.$palette;
    }

    public function test_four_editor_palettes_match_exact_private_html_and_digest():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $style) {
                $digests=[];
                foreach(Catalog::palettes() as $paletteCode=>$palette) {
                    foreach(['off','on'] as $cta) {
                        $devices=[];
                        foreach(['desktop','mobile'] as $device) {
                            $res=$this->get($this->url(
                                $product,$device,$style,$cta,$paletteCode
                            ))->assertOk();
                            $res->assertHeader('X-Frame-Options','DENY');
                            $res->assertHeader(
                                'X-Robots-Tag','noindex, nofollow, noarchive'
                            );
                            self::assertStringContainsString('no-store',
                                (string)$res->headers->get('Cache-Control'));
                            $html=(string)$res->getContent();
                            foreach([
                                'Comparar CTA secundário',
                                'Paleta da marca no CTA secundário',
                                'Revisão interna pendente',
                                'Aceite oficial pendente no Portal',
                                'Aprovação, publicação e entrega desabilitadas',
                                'class="browser '.$device.'"',
                                'sandbox=""',
                                'release-f254-'.$product.'-'.$style.
                                    '-palette-'.$paletteCode.'-'.$cta,
                            ] as $label)self::assertStringContainsString($label,$html);

                            foreach(array_keys(Catalog::palettes()) as $choice) {
                                self::assertStringContainsString(
                                    'href="'.$this->url(
                                        $product,$device,$style,$cta,$choice
                                    ).'"',
                                    str_replace('&amp;','&',$html)
                                );
                            }
                            foreach(['off','on'] as $mode) {
                                self::assertStringContainsString(
                                    'href="'.$this->url(
                                        $product,$device,$style,$mode,$paletteCode
                                    ).'"',
                                    str_replace('&amp;','&',$html)
                                );
                            }
                            self::assertSame(1,preg_match(
                                '/id="artifact-digest">([0-9a-f]{64})<\/code>/',
                                $html,$record
                            ));
                            self::assertSame(1,preg_match(
                                '/\bsrcdoc="([^"]+)"/s',$html,$capture
                            ));
                            $inner=html_entity_decode(
                                $capture[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                            );
                            self::assertSame($record[1],hash('sha256',$inner));
                            self::assertStringContainsString(
                                'noindex, nofollow',$inner
                            );
                            self::assertStringNotContainsString('<form',$inner);
                            self::assertStringNotContainsString('<script',$inner);
                            self::assertSame($cta==='on'?1:0,
                                substr_count($inner,'class="btn secondary-cta"'));
                            if($cta==='on') {
                                $expected=$palette===null
                                    ? '.secondary-cta { background: #173e69; color: #fff; }'
                                    : '.secondary-cta { background: '.
                                        $palette->buttonBackground.'; color: '.
                                        $palette->buttonText.'; border: 2px solid '.
                                        $palette->buttonText.'; }';
                                self::assertStringContainsString($expected,$inner);
                            }
                            self::assertSame(1,preg_match(
                                '/id="cta-package-evidence-digest">([0-9a-f]{64})<\/code>/',
                                $html,$proof
                            ));
                            $devices[$device]=[$inner,$record[1],$proof[1]];
                            ++$count;
                        }
                        self::assertSame($devices['desktop'],$devices['mobile'],
                            'switching responsive viewport cannot alter the site release');
                        $digests[$cta][$paletteCode]=$devices['desktop'][1];
                    }
                }
                foreach(['off','on'] as $mode) {
                    self::assertCount(4,array_unique($digests[$mode]),
                        'editor palette choice must produce distinct HTML');
                }
                foreach(array_keys(Catalog::palettes()) as $name) {
                    self::assertNotSame(
                        $digests['off'][$name],$digests['on'][$name],
                        'CTA switch must invalidate exact bytes and re-review'
                    );
                }
            }
        }
        self::assertSame(96,$count);
    }

    public function test_invalid_palette_and_extra_query_never_reach_private_preview():void
    {
        foreach([
            'default','ocean','plum','forest','../../etc/passwd',
            'https://example.invalid','UNKNOWN','on','', 'red',
        ] as $candidate) {
            $valid=in_array($candidate,['default','ocean','plum','forest'],true);
            $query=$this->url('onepage','desktop','classic','on',$candidate);
            if($valid) $this->get($query)->assertOk();
            else $this->get($query)->assertNotFound();
        }
        foreach([
            '?product=onepage&device=desktop&preset=classic&cta=on&palette[]=ocean',
            '?product=onepage&device=desktop&preset=classic&cta=on&palette=ocean&client=1',
            '?product=onepage&device=desktop&preset=classic&cta=on&palette=ocean&approve=1',
            '?product=onepage&device=desktop&preset=classic&palette=ocean',
            '?product=onepage&device=desktop&preset=classic&cta=on&palette=0',
        ] as $query) $this->get(self::PATH.$query)->assertNotFound();

        $valid=$this->url('onepage','desktop','classic','on','ocean');
        foreach(self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get($valid)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.82'])
            ->get($valid)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post($valid)->assertStatus(405);
    }
}
