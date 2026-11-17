<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Preview\PreviewBriefInput;
use App\Preview\PreviewPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Imagine\Sites\Production\PrivatePreviewOverview;
use Imagine\Sites\Production\PrivatePreviewWorkspace;
use Imagine\Sites\Production\PreviewRevisionConflict;
use OutOfBoundsException;
use PDO;
use Throwable;

final class PrivatePreviewController
{
    public function handle(Request $request, ?string $project = null, ?string $revision = null, ?string $action = null)
    {
        try {
            $principal = PreviewPrincipal::fromRequest($request);
            $workspace = new PrivatePreviewWorkspace($principal->db);
            $workspace->assertSafeRuntime();
            if ($action === 'html') {
                $version = $workspace->view($project, $principal->company, (int) $revision);
                $principal->requireCapability('read');
                return $this->secure(response($version['html'], 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
                ]));
            }

            if (!$request->isMethod('post')) {
                return $this->renderWorkspace($principal, $this->pageData($principal, $project));
            }
            if (strlen($request->getContent()) > PreviewBriefInput::MAX_FORM_BYTES) {
                return $this->secure(response('Formulário excede o limite.', 413));
            }
            $allowed = $action === 'review' ? ['_token', 'decision', 'sha256'] : [
                '_token', 'product', 'brand', 'headline', 'description', 'call_to_action', 'benefits', 'expected_revision',
            ];
            if (array_diff(array_keys($request->all()), $allowed)) {
                return $this->secure(response('Campos não permitidos.', 422));
            }
            $principal->requireCapability($action === 'review' ? 'review' : 'edit');
            $data = $this->pageData($principal, $project);
            try {
                if ($action === 'review') {
                    $input = $request->validate([
                        'sha256' => 'required|string|regex:/\A[a-f0-9]{64}\z/D',
                        'decision' => 'required|string|in:approved_internal,changes_requested',
                    ]);
                    $workspace->review($project, $principal->company, (int) $revision, $input['sha256'], $input['decision']);
                } else {
                    $brief = PreviewBriefInput::parse($request->all(), $data['brief']['productCode'] ?? null);
                    if ($project === null) {
                        $project = (string) Str::uuid();
                        $workspace->createDemonstration($project, $principal->company, $brief);
                    } else {
                        $workspace->revise($project, $principal->company, (int) $request->input('expected_revision'), $brief);
                    }
                }
                return $this->secure(redirect('/_preview/projects/'.$project));
            } catch (ValidationException $error) {
                return $this->renderWorkspace($principal, $data, $action === 'review' ? [] : $request->all(), $error->errors(), 422);
            } catch (PreviewRevisionConflict) {
                return $this->renderWorkspace($principal, $this->pageData($principal, $project), $request->all(), [
                    'expected_revision' => ['Outra versão foi criada enquanto você editava. Confira a versão atual antes de gerar novamente. Seu briefing foi mantido abaixo.'],
                ], 409);
            }
        } catch (OutOfBoundsException) {
            return $this->secure(response('Prévia indisponível.', 404));
        } catch (Throwable) {
            return $this->secure(response('Workspace indisponível ou operação não autorizada. Recarregue antes de tentar novamente.', 403));
        }
    }

    private function pageData(PreviewPrincipal $principal, ?string $project): array
    {
        $db = $principal->db;
        $company = $principal->company;
        $projects = (new PrivatePreviewOverview($db))->projects($company);
        $versions = $reviews = [];
        $brief = null;
        if ($project !== null) {
            $query = $db->prepare('SELECT revision,sha256,brief,created_at,author<>session_user AS authored_by_other FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid ORDER BY revision DESC LIMIT 100');
            $query->execute([$project, $company]);
            $versions = $query->fetchAll(PDO::FETCH_ASSOC);
            if (!$versions) {
                throw new OutOfBoundsException('Private preview unavailable');
            }
            $brief = json_decode($versions[0]['brief'], true, 12, JSON_THROW_ON_ERROR);
            $query = $db->prepare('SELECT revision,decision,created_at,reviewer=session_user AS reviewed_by_me FROM sites_preview.reviews WHERE project_id=?::uuid AND company_id=?::uuid ORDER BY revision DESC,created_at DESC LIMIT 100');
            $query->execute([$project, $company]);
            $reviews = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach ($versions as &$version) {
                $version['reviewable'] = $version['authored_by_other'] === true;
                foreach ($reviews as $review) {
                    if ($review['reviewed_by_me'] === true && (int) $review['revision'] === (int) $version['revision']) {
                        $version['reviewable'] = false;
                    }
                }
            }
            unset($version);
        }
        return compact('projects', 'project', 'versions', 'reviews', 'brief');
    }

    private function renderWorkspace(PreviewPrincipal $principal, array $data, array $submitted = [], array $fieldErrors = [], int $status = 200)
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
        foreach ($form as $key => $value) {
            if (is_string($submitted[$key] ?? null) && strlen($submitted[$key]) <= 16384 && !($key === 'product' && $data['project'] !== null)) {
                $form[$key] = $submitted[$key];
            }
        }
        return $this->secure(response()->view('preview.workspace', $data + compact('form', 'fieldErrors', 'canEdit', 'canReview'), $status));
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
