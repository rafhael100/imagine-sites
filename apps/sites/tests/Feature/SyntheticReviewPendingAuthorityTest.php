<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticInternalReviewDossier;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly;
use Tests\TestCase;

/**
 * F1-90: viewing a local preview must not silently approve internal review.
 * Even the internal status is PENDING; client acceptance remains Portal-only.
 */
final class SyntheticReviewPendingAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_REVIEW_UI=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('SITES_SYNTHETIC_HTTP');
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        parent::tearDown();
    }

    public function test_all_twelve_visual_views_show_pending_review_and_current_dossier(): void
    {
        $source = base_path('../../fixtures/sites/f1-37-copy.synthetic.json');
        $copy = json_decode((string)file_get_contents($source), true, 16, JSON_THROW_ON_ERROR);

        foreach (['onepage','landing_page'] as $product) {
            foreach (['classic','modern','warm'] as $preset) {
                $releaseRef = 'release-f38-'.$product.
                    ($preset==='classic'?'':'-'.$preset);
                $workflowRef = 'workflow-f38-'.$product.
                    ($preset==='classic'?'':'-'.$preset);
                $draft = SyntheticStructuredDraftAssembly::build(
                    $product, 'Marca Fictícia Imagine', $preset, $copy,
                    'tenant-fixture', 'site-fixture', $workflowRef, $releaseRef
                );
                $dossier = SyntheticInternalReviewDossier::fromDraft($draft);
                self::assertSame(SyntheticProductionWorkflow::INTERNAL_REVIEW,
                    $draft->workflow->state);

                foreach (['desktop','mobile'] as $device) {
                    $response = $this->get(
                        '/_lab/sites/v1/review?product='.$product.
                        '&preset='.$preset.'&device='.$device
                    )->assertOk();
                    $response->assertHeader('X-Frame-Options','DENY');
                    $response->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive');
                    $html = (string)$response->getContent();

                    self::assertStringContainsString('Revisão interna pendente', $html);
                    self::assertStringContainsString('Aceite oficial pendente no Portal', $html);
                    self::assertStringContainsString(
                        '<dd id="review-state">Revisão interna pendente</dd>', $html
                    );
                    self::assertStringContainsString($releaseRef, $html);
                    self::assertMatchesRegularExpression(
                        '/<dt>Revisão<\/dt><dd>2<\/dd>/', $html
                    );
                    self::assertStringContainsString(
                        '<code id="artifact-digest">'.$draft->artifact->digest.'</code>', $html
                    );
                    self::assertStringContainsString(
                        '<code id="dossier-digest">'.$dossier->digest.'</code>', $html
                    );
                    self::assertStringNotContainsString('Revisão interna simulada', $html);
                    self::assertStringNotContainsString('awaiting_portal_acceptance', $html);
                    self::assertStringNotContainsString('Revisão interna aprovada', $html);
                    self::assertStringContainsString(
                        'Aprovação, publicação e entrega desabilitadas', $html
                    );
                    self::assertStringNotContainsString('<form', $html);
                    self::assertStringNotContainsString('<button', $html);
                    self::assertStringNotContainsString('<script', $html);
                }
            }
        }
    }

    public function test_read_only_route_cannot_transition_the_review_workflow(): void
    {
        $route = (string)file_get_contents(base_path('routes/web.php'));
        $start = strpos($route, "Route::get('/_lab/sites/v1/review'");
        $end = strpos($route, ")->name('sites.lab.review');", $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $reviewOnly = substr($route, $start, $end-$start);

        self::assertStringContainsString(
            'SyntheticStructuredDraftAssembly::build', $reviewOnly
        );
        self::assertStringContainsString(
            'SyntheticInternalReviewDossier::fromDraft', $reviewOnly
        );
        self::assertStringNotContainsString('approve_internal', $reviewOnly);
        self::assertStringNotContainsString('portalReviewCandidate', $reviewOnly);
        self::assertStringNotContainsString('SyntheticInternalReviewDecision::decide', $reviewOnly);

        $this->post('/_lab/sites/v1/review')->assertStatus(405);
        $this->post('/_lab/sites/v1/review/approve')->assertNotFound();
        $this->post('/api/sites/publish')->assertNotFound();
    }

    public function test_no_operator_authentication_is_claimed_by_test_only_html(): void
    {
        $outer = (string)$this->get('/_lab/sites/v1/review')->assertOk()->getContent();
        self::assertStringContainsString('Somente dados fictícios', $outer);
        self::assertStringContainsString('Somente para testes Laravel em processo CLI', $outer);
        self::assertStringContainsString('Publicável</dt><dd>Não', $outer);
        self::assertStringContainsString('Aceite oficial</dt><dd>Não registrado', $outer);
        putenv('SITES_SYNTHETIC_REVIEW_UI');
        $this->get('/_lab/sites/v1/review')->assertNotFound();
    }
}
