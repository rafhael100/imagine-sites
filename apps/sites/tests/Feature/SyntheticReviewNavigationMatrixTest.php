<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\TestCase;

/**
 * F1-40: in-process navigation contract matrix, no browser or live HTTP.
 * Only the user-visible UI is checked, never an operational Portal route.
 */
final class SyntheticReviewNavigationMatrixTest extends TestCase
{
    private const PATH='/_lab/sites/v1/review';

    protected function setUp(): void
    {
        // Enable only before Laravel starts registering the lab routes.
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        putenv('SITES_SYNTHETIC_HTTP');
        parent::tearDown();
    }

    /** @return array{DOMXPath,string} */
    private function page(string $path): array
    {
        $response=$this->get($path)->assertOk();
        self::assertSame('DENY',$response->headers->get('X-Frame-Options'));
        self::assertSame('noindex, nofollow, noarchive',
            $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store',
            (string)$response->headers->get('Cache-Control'));
        $html=(string)$response->getContent();
        $prior=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument();
            self::assertTrue($dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        return [new DOMXPath($dom),$html];
    }

    public function test_every_product_device_combination_has_internal_navigation_and_real_preview_bytes(): void
    {
        foreach (['onepage','landing_page'] as $product) {
            foreach (['desktop','mobile'] as $device) {
                [$xp,$html]=$this->page(self::PATH.'?product='.$product.'&device='.$device);
                self::assertSame(1,$xp->query('//main')->length);
                self::assertSame(1,$xp->query('//h1')->length);
                self::assertSame(1,$xp->query('//iframe')->length);
                $frame=$xp->query('//iframe')->item(0);
                self::assertInstanceOf(DOMElement::class,$frame);
                self::assertTrue($frame->hasAttribute('sandbox'));
                self::assertSame('',$frame->getAttribute('sandbox'));
                self::assertSame('0',$frame->getAttribute('tabindex'));
                self::assertFalse($frame->hasAttribute('src'));
                self::assertSame('no-referrer',$frame->getAttribute('referrerpolicy'));
                self::assertSame(0,$xp->query('//form|//script|//button|//input')->length);
                self::assertSame(0,$xp->query('//link[@rel="stylesheet"]')->length);
                // Original Imagine Node logo is the ONLY approved shell image.
                // The customer iframe remains sandboxed with no external assets.
                $images=$xp->query('//img[@src]');
                self::assertSame(1,$images->length);
                $brandImage=$images->item(0);
                self::assertInstanceOf(DOMElement::class,$brandImage);
                self::assertSame('/assets/logo-imagine.png',$brandImage->getAttribute('src'));
                self::assertSame('Imagine',$brandImage->getAttribute('alt'));

                $inner=$frame->getAttribute('srcdoc');
                self::assertStringStartsWith('<!doctype html>',$inner);
                self::assertStringContainsString('data-site-product="'.
                    ($product==='onepage'?'onepage':'landing-page').'"',$inner);
                self::assertSame(1,preg_match(
                    '/id="artifact-digest">([a-f0-9]{64})<\/code>/',$html,$m));
                self::assertSame(hash('sha256',$inner),$m[1],
                    'Displayed SHA must match the precise unescaped iframe bytes.');
                self::assertSame(1,$xp->query('//*[@id="artifact-digest"]')->length);
                $qaRows=$xp->query('//ul[contains(concat(" ",normalize-space(@class)," ")," qa-list ")]/li');
                self::assertSame(10,$qaRows->length,
                    'The staff checklist must show all ten existing F1-39 checks');
                self::assertSame(10,$xp->query('//ul[contains(concat(" ",normalize-space(@class)," ")," qa-list ")]/li/strong[@class="qa-state" and normalize-space(text())="Conforme"]')->length);
                self::assertSame(1,$xp->query('//*[@id="qa"][@aria-labelledby="qa-heading"]/h3[@id="qa-heading"]')->length);
                foreach (['Títulos e nomes das seções','Links com nomes acessíveis',
                    'FAQ com estrutura semântica'] as $label) {
                    self::assertStringContainsString($label,$html);
                }
                self::assertStringContainsString('Não registrado',$html);
                self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$html);
                self::assertStringContainsString('class="browser '.$device.'"',$html);

                $urls=[];
                foreach ($xp->query('//a[@href]') as $a) {
                    self::assertInstanceOf(DOMElement::class,$a);
                    $href=$a->getAttribute('href');
                    self::assertTrue(str_starts_with($href,'/_lab/sites/v1/review?') ||
                        in_array($href,['#detalhes','#apos-previa','#sites-review-main'],true),
                        'Only local review navigation and fixed skip anchors are allowed');
                    $urls[]=$href;
                }
                self::assertCount(10,$urls,
                    'Product/device/theme links plus two real skip targets and identity anchor.');
                self::assertSame('#sites-review-main',$urls[0],
                    'Keyboard first link must be the real review content skip');
                self::assertSame(1,$xp->query('//main[@id="sites-review-main"][@tabindex="-1"]')->length);
                self::assertContains('#apos-previa',$urls);
                self::assertContains('#detalhes',$urls);
                self::assertSame(1,$xp->query('//a[@href="#apos-previa"]')->length);
                self::assertSame(1,$xp->query('//*[@id="apos-previa"][@tabindex="-1"]')->length);
                foreach (['onepage','landing_page'] as $other) {
                    self::assertContains(self::PATH.'?product='.$other.'&device='.$device.'&preset=classic',$urls);
                }
                foreach (['desktop','mobile'] as $other) {
                    self::assertContains(self::PATH.'?product='.$product.'&device='.$other.'&preset=classic',$urls);
                }
                $current=$xp->query('//nav[@aria-label="Alternar produto"]/a[@aria-current="page"]');
                self::assertSame(1,$current->length);
                self::assertSame(1,$xp->query('//nav[@aria-label="Alternar dispositivo"]/a[@aria-current="page"]')->length);
                self::assertSame(1,$xp->query('//nav[@aria-label="Alternar tema"]/a[@aria-current="page"]')->length);
                foreach (['classic','modern','warm'] as $theme) {
                    self::assertContains(self::PATH.'?product='.$product.'&device='.$device.'&preset='.$theme,$urls);
                }
            }
        }
    }

    public function test_default_review_uses_onepage_and_desktop_without_external_state(): void
    {
        [$xp,$html]=$this->page(self::PATH);
        self::assertSame(1,$xp->query('//div[@class="browser desktop"]')->length);
        self::assertStringContainsString('release-f38-onepage',$html);
    }

    public function test_unknown_duplicated_or_encoded_navigation_values_fail_closed(): void
    {
        foreach ([
            '?product=blog',
            '?product=onepage%2Fsomething',
            '?product[]=onepage',
            '?device=tablet',
            '?device[]=mobile',
            '?product=',
            '?device=',
            '?preset=',
            '?preset[]=classic',
            '?preset=../modern',
        ] as $query) {
            self::assertSame(404,$this->get(self::PATH.$query)->getStatusCode(),
                'Rejected route query: '.$query);
        }
    }

    public function test_only_get_may_inspect_local_preview_without_mutating_release(): void
    {
        foreach (['post','put','patch','delete'] as $method) {
            $this->$method(self::PATH)->assertStatus(405);
        }
        foreach (['/api/sites','/api/publish','/api/leads','/admin','/checkout'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_nonloopback_request_is_denied_before_html_rendering(): void
    {
        $this->withServerVariables(['REMOTE_ADDR'=>'192.0.2.49'])
            ->get(self::PATH.'?product=onepage')->assertNotFound();
    }
}
