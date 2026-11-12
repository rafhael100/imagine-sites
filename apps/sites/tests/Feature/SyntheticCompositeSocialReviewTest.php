<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-212: one private reviewer for canonical visual design + social copy. */
final class SyntheticCompositeSocialReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/composite-social';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW',
        'SITES_F212_COMPOSITE_SOCIAL_REVIEW',
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

    private function url(string $product,string $device,string $preset,
        string $order,string $nav,string $palette,string $social):string
    {
        return self::PATH.'?product='.$product.'&device='.$device.
            '&preset='.$preset.'&order='.$order.'&nav='.$nav.
            '&palette='.$palette.'&social='.$social;
    }

    public function test_48_composite_views_have_exact_html_and_metadata_identity():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['services_process_faq','faq_process_services'] as $order) {
                    foreach(['off','on'] as $social) {
                        $palette=$preset==='modern'?'ocean':'default';
                        $byDevice=[];
                        foreach(['desktop','mobile'] as $device) {
                            $response=$this->get($this->url(
                                $product,$device,$preset,$order,'off',$palette,$social
                            ))->assertOk();
                            $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                            $response->assertHeader('X-Frame-Options','DENY');
                            self::assertStringContainsString('no-store',
                                (string)$response->headers->get('Cache-Control'));
                            $csp=(string)$response->headers->get('Content-Security-Policy');
                            foreach(["script-src 'none'","connect-src 'none'",
                                "form-action 'none'","frame-ancestors 'none'"] as $rule) {
                                self::assertStringContainsString($rule,$csp);
                            }
                            $outer=(string)$response->getContent();
                            foreach([
                                'Visual completo e compartilhamento',
                                'Metadados de compartilhamento',
                                'Ordem das seções',
                                'Cores da marca',
                                'SHA-256 da prévia selecionada',
                                'Aceite oficial pendente no Portal',
                                'Aprovação, publicação e entrega desabilitadas',
                                'sandbox=""','class="browser '.$device.'"',
                            ] as $label)self::assertStringContainsString($label,$outer);
                            self::assertStringNotContainsString('<script',$outer);
                            self::assertStringNotContainsString('<form',$outer);
                            self::assertSame(1,preg_match(
                                '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$digest
                            ));
                            self::assertSame(1,preg_match(
                                '/id="composite-social-digest">([a-f0-9]{64})<\/code>/',
                                $outer,$compositeDigest
                            ));
                            self::assertSame($digest[1],$compositeDigest[1]);
                            self::assertSame(1,preg_match(
                                '/\bsrcdoc="([^"]+)"/s',$outer,$frame
                            ));
                            $inner=html_entity_decode(
                                $frame[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                            );
                            self::assertSame(hash('sha256',$inner),$digest[1]);
                            self::assertStringContainsString('noindex, nofollow',$inner);
                            self::assertStringNotContainsString('<form',$inner);
                            self::assertStringNotContainsString('<script',$inner);
                            self::assertStringNotContainsString('og:image',$inner);
                            self::assertStringNotContainsString('og:url',$inner);
                            self::assertSame($social==='on'?12:5,
                                substr_count($inner,'<meta '));
                            if($social==='on'){
                                self::assertStringContainsString(
                                    'id="composite-card-title"',$outer
                                );
                                self::assertStringContainsString(
                                    'Inspecionado a partir dos bytes canônicos',$outer
                                );
                                self::assertStringContainsString('og:title',$inner);
                            }else{
                                self::assertStringContainsString(
                                    'id="composite-social-card-disabled"',$outer
                                );
                                self::assertStringNotContainsString(
                                    'id="composite-card-title"',$outer
                                );
                            }
                            $check='&amp;order='.$order.'&amp;nav=off'.
                                '&amp;palette='.$palette.'&amp;social='.$social;
                            self::assertStringContainsString($check,$outer,
                                'Main reviewer links must retain all visual and social choices');
                            self::assertStringContainsString(
                                $this->url($product,$device,$preset,
                                    $order,'off',$palette,$social),
                                str_replace('&amp;','&',$outer),
                                'Every selected design must be navigable with all dimensions'
                            );
                            $byDevice[$device]=$digest[1];
                            ++$count;
                        }
                        self::assertSame($byDevice['desktop'],$byDevice['mobile']);
                    }
                }
            }
        }
        self::assertSame(48,$count);
    }

    public function test_menu_and_palette_tabs_preserve_combined_social_mode():void
    {
        $base=$this->url(
            'onepage','mobile','warm','faq_process_services','on','plum','on'
        );
        $html=(string)$this->get($base)->assertOk()->getContent();
        self::assertStringContainsString('id="composite-card-title"',$html);
        foreach([
            '&amp;order=faq_process_services&amp;nav=off&amp;palette=plum&amp;social=on',
            '&amp;order=faq_process_services&amp;nav=on&amp;palette=plum&amp;social=on',
            '&amp;order=faq_process_services&amp;nav=on&amp;palette=forest&amp;social=on',
            '&amp;order=faq_process_services&amp;nav=on&amp;palette=plum&amp;social=off',
            '&amp;order=services_faq_process&amp;nav=on&amp;palette=plum&amp;social=on',
        ] as $fragment)self::assertStringContainsString($fragment,$html);
        self::assertStringContainsString(
            'product=landing_page&amp;device=mobile&amp;preset=warm'.
            '&amp;order=faq_process_services&amp;nav=off&amp;palette=plum&amp;social=on',
            $html,'Landing switch must turn off disallowed Onepage-only navigation'
        );
        foreach(['default','ocean','plum','forest'] as $palette) {
            $this->get($this->url(
                'onepage','desktop','classic','services_process_faq',
                'off',$palette,'on'
            ))->assertOk();
        }
    }

    public function test_rejects_unknown_options_external_ip_and_missing_flags():void
    {
        foreach([
            '',
            '?order=services_process_faq&nav=off&palette=default',
            '?order[]=services_process_faq&nav=off&palette=default&social=off',
            '?order=services_process_faq&nav=on&palette=default&social=on&product=landing_page',
            '?order=services_process_faq&nav=off&palette=external&social=on',
            '?order=services_process_faq&nav=off&palette=default&social[]=on',
            '?order=services_process_faq&nav=off&palette=default&social=on&url=https://x.invalid',
            '?order=services_process_faq&nav=off&palette=default&social=on&publish=true',
            '?order=unknown&nav=off&palette=default&social=off',
        ] as $query)$this->get(self::PATH.$query)->assertNotFound();

        $valid='?order=services_process_faq&nav=off&palette=default&social=off';
        foreach(self::FLAGS as $flag){
            putenv($flag);
            try {$this->get(self::PATH.$valid)->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.99'])
            ->get(self::PATH.$valid)->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR'=>'127.0.0.1'])
            ->post(self::PATH.$valid)->assertStatus(405);
    }
}
