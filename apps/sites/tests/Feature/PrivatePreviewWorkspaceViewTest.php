<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewNavigation;
use Imagine\Sites\Production\PreviewQueueQuery;
use Tests\TestCase;

final class PrivatePreviewWorkspaceViewTest extends TestCase
{
    private function page(): array
    {
        return [
            'projects' => [], 'project' => null, 'brief' => null,
            'versions' => [], 'reviews' => [], 'fieldErrors' => [],
            'navigation' => PreviewNavigation::fromQuery([]),
            'currentRevision' => null, 'queueNext' => null,
            'historyOlder' => null, 'historyNewer' => null, 'reviewsTruncated' => false,
            'form' => [
                'product' => 'onepage', 'brand' => '', 'headline' => '',
                'description' => '', 'call_to_action' => '', 'benefits' => '',
            ],
            'canEdit' => true, 'canReview' => false,
        ];
    }

    public function test_author_can_render_the_empty_workspace_without_a_template_error(): void
    {
        $html = view('preview.workspace', $this->page())->render();

        self::assertStringContainsString('DEMONSTRAÇÃO PRIVADA', $html);
        self::assertStringContainsString('Novo projeto demonstrativo', $html);
        self::assertStringContainsString('action="/_preview/projects"', $html);
        self::assertStringContainsString('Criar demonstração', $html);
        self::assertStringNotContainsString('@endforeach', $html);
    }

    public function test_reviewer_renders_a_version_and_review_action_without_an_editor_form(): void
    {
        $data = $this->page();
        $data['project'] = '11111111-1111-4111-8111-111111111111';
        $data['currentRevision'] = 1;
        $data['canEdit'] = false;
        $data['canReview'] = true;
        $data['form']['brand'] = 'Marca fictícia';
        $data['versions'] = [[
            'revision' => 1, 'sha256' => str_repeat('a', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => true,
        ]];

        $html = view('preview.workspace', $data)->render();

        self::assertStringContainsString('Briefing da versão atual', $html);
        self::assertStringContainsString('Marca fictícia', $html);
        self::assertStringContainsString('/versions/1/html', $html);
        self::assertStringContainsString('/versions/1/review', $html);
        self::assertStringContainsString('Registrar revisão editorial', $html);
        self::assertStringNotContainsString('name="brand"', $html);
        self::assertStringNotContainsString('Gerar nova versão privada', $html);
    }

    public function test_validation_errors_render_all_groups_and_escape_preserved_briefing(): void
    {
        $data = $this->page();
        $data['form']['brand'] = '<img src=x onerror=alert(1)>';
        $data['form']['benefits'] = '</textarea><script>alert(1)</script>';
        $data['fieldErrors'] = [
            'brand' => ['Mensagem da marca', '<script>erro</script>'],
            'benefits' => ['Mensagem dos benefícios'],
        ];

        $html = view('preview.workspace', $data)->render();

        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('<li>Mensagem da marca</li>', $html);
        self::assertStringContainsString('<li>Mensagem dos benefícios</li>', $html);
        self::assertStringContainsString('&lt;script&gt;erro&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img ', $html);
        self::assertStringNotContainsString('@endforeach', $html);
    }

    public function test_queue_titles_are_escaped_and_navigation_retains_selected_filters(): void
    {
        $data = $this->page();
        $data['navigation'] = PreviewNavigation::fromQuery(['status' => 'changes_requested', 'format' => 'onepage']);
        $data['queueNext'] = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.000000Z', '11111111-1111-4111-8111-111111111111');
        $data['projects'] = [[
            'id' => '11111111-1111-4111-8111-111111111111', 'product' => 'onepage',
            'brand' => '<script>Marca</script>', 'headline' => 'Chamada identificável',
            'latest_revision' => 2, 'latest_created_at' => '2026-11-17 12:00:00+00',
            'editorial_status' => 'changes_requested',
        ]];

        $html = view('preview.workspace', $data)->render();

        self::assertStringContainsString('&lt;script&gt;Marca&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Chamada identificável', $html);
        self::assertStringContainsString('/_preview/projects/11111111-1111-4111-8111-111111111111?status=changes_requested&amp;format=onepage', $html);
        self::assertStringContainsString('/_preview?status=changes_requested&amp;format=onepage&amp;cursor=', $html);
        self::assertStringContainsString('Próximos projetos', $html);
        self::assertStringContainsString('17/11/2026 09:00', $html);
    }

    public function test_older_history_page_keeps_the_current_brief_and_revision_for_editing(): void
    {
        $data = $this->page();
        $data['project'] = '11111111-1111-4111-8111-111111111111';
        $data['navigation'] = PreviewNavigation::fromQuery(['before_revision' => '21'], true);
        $data['currentRevision'] = 60;
        $data['historyOlder'] = 1;
        $data['historyNewer'] = 20;
        $data['form']['headline'] = 'Briefing atual da versão 60';
        $data['versions'] = [[
            'revision' => 20, 'sha256' => str_repeat('a', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => false,
        ]];

        $html = view('preview.workspace', $data)->render();

        self::assertStringContainsString('name="expected_revision" value="60"', $html);
        self::assertStringContainsString('value="Briefing atual da versão 60"', $html);
        self::assertStringContainsString('<strong>Versão 20</strong>', $html);
        self::assertStringNotContainsString('<span class="status">Atual</span>', $html);
        self::assertStringContainsString('after_revision=20', $html);
        self::assertStringContainsString('before_revision=1', $html);
    }
}
