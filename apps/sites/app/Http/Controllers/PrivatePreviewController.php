<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Preview\PreviewBriefInput;
use App\Preview\PreviewNavigation;
use App\Preview\PreviewPrincipal;
use App\Preview\PreviewReviewInput;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagine\Sites\Production\PrivatePreviewComparison;
use Imagine\Sites\Production\PrivatePreviewOverview;
use Imagine\Sites\Production\PrivatePreviewHistory;
use Imagine\Sites\Production\PrivatePreviewWorkspace;
use Imagine\Sites\Production\PreviewRevisionConflict;
use InvalidArgumentException;
use OutOfBoundsException;
use Throwable;

final class PrivatePreviewController
{
    public function handle(Request $request, ?string $project = null, ?string $revision = null, ?string $action = null)
    {
        try {
            $principal = PreviewPrincipal::fromRequest($request);
            $workspace = new PrivatePreviewWorkspace($principal->db);
            $workspace->assertSafeRuntime();
            try {
                $navigation = $request->isMethod('post')
                    ? PreviewNavigation::fromPostQuery($request->query(), $project !== null)
                    : PreviewNavigation::fromQuery($request->query(), $project !== null,
                        $request->isMethod('get') && $action === null);
            } catch (InvalidArgumentException) {
                return $this->secure(response('Filtros, página ou comparação inválidos. Volte ao workspace e selecione novamente.', 422));
            }
            if ($action === 'html') {
                $version = $workspace->view($project, $principal->company, (int) $revision);
                $principal->requireCapability('read');
                return $this->secure(response($version['html'], 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
                ]));
            }

            if (!$request->isMethod('post')) {
                return $this->renderWorkspace($principal, $this->pageData($principal, $project, $navigation));
            }
            if (strlen($request->getContent()) > PreviewBriefInput::MAX_FORM_BYTES) {
                return $this->secure(response('Formulário excede o limite.', 413));
            }
            $allowed = $action === 'review' ? ['_token', 'decision', 'sha256', 'note'] : [
                '_token', 'product', 'brand', 'headline', 'description', 'call_to_action', 'benefits', 'expected_revision',
            ];
            // Navigation parameters are never briefing, identity or authority.
            $payload = $request->isJson() ? $request->json()->all() : $request->request->all();
            if ($request->allFiles() || array_diff(array_keys($payload), $allowed)) {
                return $this->secure(response('Campos não permitidos.', 422));
            }
            $principal->requireCapability($action === 'review' ? 'review' : 'edit');
            $data = $this->pageData($principal, $project, $navigation);
            try {
                if ($action === 'review') {
                    $input = PreviewReviewInput::parse($payload);
                    $workspace->review($project, $principal->company, (int) $revision,
                        $input['sha256'], $input['decision'], $input['note']);
                } else {
                    $brief = PreviewBriefInput::parse($payload, $data['brief']['productCode'] ?? null);
                    if ($project === null) {
                        $project = (string) Str::uuid();
                        $workspace->createDemonstration($project, $principal->company, $brief);
                    } else {
                        $workspace->revise($project, $principal->company, (int) $payload['expected_revision'], $brief);
                    }
                }
                return $this->secure(redirect($navigation->projectUrl($project,
                    $action === 'review' ? $navigation->beforeRevision : null,
                    $action === 'review' ? $navigation->afterRevision : null)));
            } catch (ValidationException $error) {
                return $this->renderWorkspace($principal, $data, $action === 'review' ? [] : $payload, $error->errors(), 422,
                    $action === 'review' ? PreviewReviewInput::preserve($payload, (int) $revision) : null);
            } catch (PreviewRevisionConflict) {
                if ($action === 'review') {
                    return $this->renderWorkspace($principal, $this->pageData($principal, $project, $navigation), [], [
                        'review' => ['Seu acesso já registrou um parecer para esta versão. O texto enviado foi preservado abaixo e não foi registrado novamente.'],
                    ], 409, PreviewReviewInput::preserve($payload, (int) $revision));
                }
                return $this->renderWorkspace($principal, $this->pageData($principal, $project, $navigation), $payload, [
                    'expected_revision' => ['Outra versão foi criada enquanto você editava. Confira a versão atual antes de gerar novamente. Seu briefing foi mantido abaixo.'],
                ], 409);
            }
        } catch (OutOfBoundsException) {
            return $this->secure(response('Prévia indisponível.', 404));
        } catch (Throwable) {
            return $this->secure(response('Workspace indisponível ou operação não autorizada. Recarregue antes de tentar novamente.', 403));
        }
    }

    private function pageData(PreviewPrincipal $principal, ?string $project, PreviewNavigation $navigation): array
    {
        $projects = [];
        $versions = $reviews = $currentReviews = [];
        $brief = null;
        $comparison = null;
        $currentRevision = $currentSha256 = $queueNext = $historyOlder = $historyNewer = null;
        $reviewsTruncated = $currentReviewsTruncated = false;
        if ($project !== null) {
            $history = (new PrivatePreviewHistory($principal->db))->page($principal->company, $project,
                $navigation->beforeRevision, $navigation->afterRevision);
            $versions = $history['versions'];
            $reviews = $history['reviews'];
            $currentReviews = $history['current_reviews'];
            $brief = $history['brief'];
            $currentRevision = $history['current_revision'];
            $currentSha256 = $history['current_sha256'];
            $historyOlder = $history['older_before'];
            $historyNewer = $history['newer_after'];
            $reviewsTruncated = $history['reviews_truncated'];
            $currentReviewsTruncated = $history['current_reviews_truncated'];
            if ($navigation->compareFrom !== null) {
                $comparison = (new PrivatePreviewComparison($principal->db))->compare($principal->company, $project,
                    $navigation->compareFrom, $navigation->compareTo);
            }
        } else {
            $queue = (new PrivatePreviewOverview($principal->db))->page($principal->company, $navigation->queue);
            $projects = $queue['items'];
            $queueNext = $queue['next_cursor'];
        }
        return compact('projects', 'project', 'versions', 'reviews', 'brief', 'currentRevision', 'currentSha256', 'comparison',
            'queueNext', 'historyOlder', 'historyNewer', 'reviewsTruncated', 'currentReviews', 'currentReviewsTruncated', 'navigation');
    }

    private function renderWorkspace(PreviewPrincipal $principal, array $data, array $submitted = [], array $fieldErrors = [], int $status = 200, ?array $reviewSubmission = null)
    {
        $principal->requireCapability('read');
        $canEdit = $principal->allows('edit');
        $canReview = $principal->allows('review');
        $brief = $data['brief'];
        $form = [
            'product' => $brief['productCode'] ?? 'onepage', 'brand' => $brief['brand'] ?? '',
            'headline' => $brief['headline'] ?? '', 'description' => $brief['description'] ?? '',
            'call_to_action' => $brief['callToAction'] ?? '', 'benefits' => implode("\n", $brief['benefits'] ?? []),
        ];
        // Preserve failed input only in this no-store response, never in the cookie session.
        foreach (PreviewBriefInput::preserve($submitted) as $key => $value) {
            if (!($key === 'product' && $data['project'] !== null)) {
                $form[$key] = $value;
            }
        }
        if ($reviewSubmission !== null) {
            $reviewSubmission['canRetry'] = false;
            foreach ($data['versions'] as $version) {
                if ($canReview && $status === 422 && $version['reviewable'] &&
                    $version['revision'] === $reviewSubmission['revision'] &&
                    $version['sha256'] === $reviewSubmission['sha256']) {
                    $reviewSubmission['canRetry'] = true;
                    break;
                }
            }
        }
        return $this->secure(response()->view('preview.workspace', $data + compact('form', 'fieldErrors', 'canEdit', 'canReview', 'reviewSubmission'), $status));
    }

    private function secure($response)
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => $response->headers->get('Content-Security-Policy')
                ?: "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }
}
