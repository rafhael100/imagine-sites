<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** F1-69: no real HTTP serving, only an in-process Laravel testing harness. */
final class SyntheticContentRevisionLabTest extends TestCase
{
    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        putenv('SITES_SYNTHETIC_HTTP');
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        parent::tearDown();
    }

    public function test_each_copy_change_requires_a_new_private_review():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['brand','headline','description','callToAction','benefits'] as $field) {
                    $response=$this->get('/_lab/sites/v1/content-revision?product='.
                        $product.'&preset='.$preset.'&field='.$field)->assertOk();
                    $response->assertHeader('Cache-Control','max-age=0, no-store, private');
                    $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                    $body=$response->json();
                    self::assertSame('sites.synthetic.content-revision-lab.v1',$body['schemaVersion']);
                    self::assertTrue($body['syntheticOnly']);
                    self::assertSame($product,$body['product']);
                    self::assertSame($preset,$body['preset']);
                    self::assertSame([$field],$body['changedFields']);
                    self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D',$body['previousDigest']);
                    self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D',$body['nextDigest']);
                    self::assertNotSame($body['previousDigest'],$body['nextDigest']);
                    self::assertNotSame($body['previousReleaseRef'],$body['nextReleaseRef']);
                    self::assertSame(4,$body['nextRevision']);
                    self::assertSame('drafting',$body['nextState']);
                    self::assertFalse($body['oldCandidateMatchesNewSnapshot']);
                    self::assertTrue($body['newReviewRequired']);
                    self::assertFalse($body['operationallyVerified']);
                    self::assertFalse($body['publishable']);
                    self::assertFalse($body['officialClientAcceptance']);
                    self::assertArrayNotHasKey('accept',$body);
                    self::assertArrayNotHasKey('deploy',$body);
                }
            }
        }
    }

    public function test_unrecognized_or_ambiguous_queries_are_denied():void
    {
        foreach([
            '', '?product=onepage&preset=classic',
            '?product=onepage&preset=classic&field=',
            '?product[]=onepage&preset=classic&field=headline',
            '?product=onepage&preset[]=classic&field=headline',
            '?product=onepage&preset=classic&field[]=headline',
            '?product=onepage&preset=classic&field=script',
            '?product=other&preset=classic&field=headline',
            '?product=onepage&preset=unknown&field=headline',
            '?product=onepage&preset=classic&field=headline&approved=true',
        ] as $query) {
            $this->get('/_lab/sites/v1/content-revision'.$query)->assertNotFound();
        }
        $this->post('/_lab/sites/v1/content-revision?product=onepage&preset=classic&field=headline')
            ->assertStatus(405);
        $this->post('/_lab/sites/v1/approve')->assertNotFound();
        $this->get('/api/publish')->assertNotFound();
    }

    public function test_feature_disappears_without_explicit_lab_flags():void
    {
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        $this->get('/_lab/sites/v1/content-revision?product=onepage&preset=classic&field=headline')
            ->assertNotFound();
    }
}
