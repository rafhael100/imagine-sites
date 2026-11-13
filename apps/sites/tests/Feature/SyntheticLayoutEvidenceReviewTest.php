<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * F1-201: private diff reviewer displays canonical evidence without ever
 * accepting a viewer's identity or granting an approval/CRM operation.
 */
final class SyntheticLayoutEvidenceReviewTest extends TestCase
{
    private const PATH='/_lab/sites/v1/layout-diff';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP',
        'SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW',
        'SITES_SYNTHETIC_ORDER_REVIEW',
        'SITES_SYNTHETIC_ANCHOR_REVIEW',
        'SITES_F181_COMBINED_LAYOUT',
        'SITES_F188_LAYOUT_DIFF',
    ];

    protected function setUp():void
    {
        foreach(self::FLAGS as $name)putenv($name.'=1');
        parent::setUp();
    }
    protected function tearDown():void
    {
        foreach(self::FLAGS as $name)putenv($name);
        parent::tearDown();
    }

    /** @return array{string,string,array<string,mixed>} */
    private function evidence(string $product,string $preset,
        string $device,string $order,string $nav):array
    {
        $query='?product='.$product.'&device='.$device.
            '&preset='.$preset.'&order='.$order.'&nav='.$nav;
        $response=$this->get(self::PATH.$query)->assertOk();
        $response->assertHeader('X-Frame-Options','DENY');
        $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
        self::assertStringContainsString('no-store',
            (string)$response->headers->get('Cache-Control'));
        $html=(string)$response->getContent();
        self::assertStringContainsString('Examinar registro canônico da alteração',$html);
        self::assertStringContainsString('Evidência da revisão — SHA-256',$html);
        self::assertStringContainsString('Pendente — não publicada',$html);
        self::assertStringContainsString('Aprovação, publicação e entrega desabilitadas',$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertSame(1,preg_match(
            '/id="layout-evidence-digest">([a-f0-9]{64})<\/code>/',
            $html,$m
        ));
        self::assertSame(1,preg_match(
            '/<pre id="layout-evidence-json">([\s\S]*?)<\/pre>/',
            $html,$jsonMatches
        ));
        $raw=html_entity_decode(
            $jsonMatches[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
        );
        self::assertSame($m[1],hash('sha256',$raw),
            'Exact rendered evidence JSON must match the displayed digest');
        $record=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame('sites.synthetic.layout-change-evidence.v1',
            $record['schemaVersion']);
        self::assertTrue($record['syntheticOnly']);
        self::assertSame('tenant-f188-fictional',$record['tenantRef']);
        self::assertSame('site-f188-fictional',$record['siteRef']);
        self::assertSame('pending',$record['reviewerDecisionStatus']);
        self::assertSame(3,$record['previousRevision']);
        self::assertSame(4,$record['nextDraftRevision']);
        self::assertTrue($record['newInternalReviewRequired']);
        self::assertTrue($record['portalAuthorityRequired']);
        self::assertFalse($record['officialClientAcceptance']);
        self::assertFalse($record['publishable']);
        self::assertFalse($record['crmDeliveryAuthorized']);
        self::assertSame($nav==='on',$record['nextNavigation']);
        self::assertSame($record['previousNavigation'],false);
        self::assertSame('release-f188-after-'.$product.'-'.$preset.'-'.$order.'-'.$nav,
            $record['nextReleaseRef']);
        self::assertSame(1,preg_match(
            '/id="layout-previous-digest">([a-f0-9]{64})<\/code>/',
            $html,$prior
        ));
        self::assertSame(1,preg_match(
            '/id="layout-next-digest">([a-f0-9]{64})<\/code>/',
            $html,$next
        ));
        self::assertSame($record['previousArtifactDigest'],$prior[1]);
        self::assertSame($record['nextArtifactDigest'],$next[1]);
        self::assertNotSame($prior[1],$next[1]);
        return [$m[1],$raw,$record];
    }

    public function test_real_review_evidence_is_stable_between_viewports():void
    {
        $unique=[];
        $orders=['services_faq_process','faq_process_services'];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach($orders as $order) {
                    foreach($product==='onepage'?['off','on']:['off'] as $nav) {
                        [$desktopDigest,$desktopJson,$desktopRecord]=
                            $this->evidence($product,$preset,'desktop',$order,$nav);
                        [$mobileDigest,$mobileJson,$mobileRecord]=
                            $this->evidence($product,$preset,'mobile',$order,$nav);
                        self::assertSame($desktopDigest,$mobileDigest);
                        self::assertSame($desktopJson,$mobileJson);
                        self::assertSame($desktopRecord,$mobileRecord);
                        $unique[]=$desktopDigest;
                    }
                }
            }
        }
        self::assertCount(18,array_unique($unique),
            'Each of 18 modified product/visual/layout/menu states has distinct evidence');
    }

    public function test_evidence_disappears_on_legacy_routes_and_invalid_states():void
    {
        $previous=(string)$this->get(
            '/_lab/sites/v1/layout-options?order=services_faq_process&nav=off'
        )->assertOk()->getContent();
        self::assertStringNotContainsString('layout-evidence-digest',$previous);
        self::assertStringNotContainsString('layout-evidence-json',$previous);
        foreach([
            '?order=services_process_faq&nav=off',
            '?product=landing_page&order=services_process_faq&nav=on',
            '?order=faq_process_services&nav[]=on',
            '?order=faq_process_services&nav=on&clientId=123',
        ] as $query)$this->get(self::PATH.$query)->assertNotFound();
        foreach(self::FLAGS as $flag) {
            putenv($flag);
            try {$this->get(self::PATH.
                '?order=faq_process_services&nav=off')->assertNotFound();}
            finally {putenv($flag.'=1');}
        }
        $this->post(self::PATH.
            '?order=faq_process_services&nav=off')->assertStatus(405);
    }
}
