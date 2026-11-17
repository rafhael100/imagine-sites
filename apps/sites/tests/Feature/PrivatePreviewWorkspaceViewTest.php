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
            'currentRevision' => null, 'queueNext' => null, 'comparison' => null,
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

    public function test_comparison_escapes_both_sides_preserves_benefit_order_and_current_edit_baseline(): void
    {
        $data = $this->page();
        $data['project'] = '11111111-1111-4111-8111-111111111111';
        $data['currentRevision'] = 61;
        $data['historyOlder'] = 21;
        $data['historyNewer'] = 40;
        $data['navigation'] = PreviewNavigation::fromQuery([
            'status' => 'changes_requested', 'format' => 'onepage', 'before_revision' => '41',
            'compare_from' => '1', 'compare_to' => '60',
        ], true);
        $data['form']['headline'] = 'Briefing atual da versão 61';
        $data['canReview'] = true;
        $data['versions'] = [[
            'revision' => 40, 'sha256' => str_repeat('c', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => true,
        ]];
        $before = [
            'productCode' => 'onepage', 'brand' => 'Marca fictícia',
            'headline' => '<script data-comparison-probe="literal">Antes</script>',
            'description' => 'Descrição anterior', 'callToAction' => 'Conheça',
            'benefits' => ['Primeiro benefício', 'Segundo benefício'],
        ];
        $after = $before;
        $after['headline'] = 'Chamada posterior';
        $after['description'] = '<img src=x onerror=alert(1)>';
        $after['benefits'] = ['Segundo benefício', '<strong data-comparison-probe="literal">sem formatação</strong>', 'Primeiro benefício'];
        $data['comparison'] = [
            'from' => ['revision' => 1, 'sha256' => str_repeat('a', 64), 'created_at' => '2026-11-16 12:00:00+00', 'brief' => $before],
            'to' => ['revision' => 60, 'sha256' => str_repeat('b', 64), 'created_at' => '2026-11-17 12:00:00+00', 'brief' => $after],
            'changed_fields' => ['headline', 'description', 'benefits'],
        ];

        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $from = '//*[@data-compare-side="from"]';
        $to = '//*[@data-compare-side="to"]';
        self::assertSame(1, $xpath->query('//*[@data-testid="editorial-comparison"]')->length);
        self::assertSame('1', $xpath->evaluate('string('.$from.'/@data-compare-revision)'));
        self::assertSame('60', $xpath->evaluate('string('.$to.'/@data-compare-revision)'));
        self::assertSame($before['headline'], $xpath->evaluate('string('.$from.'//*[@data-field="headline"])'));
        self::assertSame($after['description'], $xpath->evaluate('string('.$to.'//*[@data-field="description"])'));
        self::assertSame('true', $xpath->evaluate('string('.$to.'//*[@data-field="headline"]/@data-field-changed)'));
        self::assertSame('false', $xpath->evaluate('string('.$to.'//*[@data-field="brand"]/@data-field-changed)'));
        self::assertSame($before['benefits'], array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query($from.'//*[@data-field="benefits"]//li'))));
        self::assertSame($after['benefits'], array_map(static fn ($node) => $node->textContent, iterator_to_array($xpath->query($to.'//*[@data-field="benefits"]//li'))));
        self::assertSame(0, $xpath->query('//script|//img|//*[@data-comparison-probe]')->length);
        self::assertStringContainsString('SHA-256: '.str_repeat('a', 64), $html);
        self::assertStringContainsString('SHA-256: '.str_repeat('b', 64), $html);
        self::assertStringContainsString('16/11/2026 09:00', $html);
        self::assertStringContainsString('17/11/2026 09:00', $html);
        self::assertStringContainsString('Campos alterados:', $html);
        self::assertStringContainsString('Chamada principal, Descrição, Benefícios.', $html);
        self::assertSame('61', $xpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
        self::assertSame('Briefing atual da versão 61', $xpath->evaluate('string(//input[@name="headline"]/@value)'));
        self::assertSame('1', $xpath->evaluate('string(//input[@name="compare_from"]/@value)'));
        self::assertSame('60', $xpath->evaluate('string(//input[@name="compare_to"]/@value)'));
        self::assertSame('41', $xpath->evaluate('string(//form[@method="get"]//input[@name="before_revision"]/@value)'));
        self::assertSame('changes_requested', $xpath->evaluate('string(//form[@method="get"]//input[@name="status"]/@value)'));
        foreach ($xpath->query('//form[@method="post"]/@action') as $action) {
            self::assertStringNotContainsString('compare_', $action->nodeValue);
            self::assertStringContainsString('status=changes_requested&format=onepage&before_revision=41', $action->nodeValue);
        }
        self::assertStringContainsString('before_revision=21&amp;compare_from=1&amp;compare_to=60', $html);
        self::assertStringContainsString('after_revision=40&amp;compare_from=1&amp;compare_to=60', $html);
        self::assertStringContainsString('before_revision=41&amp;compare_from=39&amp;compare_to=40', $html);
        self::assertSame('/_preview/projects/'.$data['project'].'?status=changes_requested&format=onepage&before_revision=41', $xpath->evaluate('string(//a[text()="Fechar comparação"]/@href)'));
        self::assertStringNotContainsString('@endforeach', $html);
    }

    public function test_reader_can_compare_identical_briefs_without_an_edit_or_review_form(): void
    {
        $data = $this->page();
        $data['project'] = '11111111-1111-4111-8111-111111111111';
        $data['currentRevision'] = 2;
        $data['canEdit'] = false;
        $data['navigation'] = PreviewNavigation::fromQuery(['compare_from' => '1', 'compare_to' => '2'], true);
        $brief = ['productCode' => 'landing_page', 'brand' => 'Marca fictícia', 'headline' => 'Chamada',
            'description' => 'Descrição', 'callToAction' => 'Conheça', 'benefits' => ['Benefício']];
        $version = ['sha256' => str_repeat('a', 64), 'created_at' => '2026-11-17 12:00:00+00', 'brief' => $brief];
        $data['comparison'] = ['from' => ['revision' => 1] + $version, 'to' => ['revision' => 2] + $version, 'changed_fields' => []];

        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertStringContainsString('Nenhum campo do briefing mudou entre estas versões.', $html);
        self::assertSame(0, $xpath->query('//form[@method="post"]|//*[@data-field-changed="true"]')->length);
        self::assertSame(12, $xpath->query('//*[@data-field-changed="false"]')->length);
        self::assertSame('Landing Page', $xpath->evaluate('string(//*[@data-compare-side="to"]//*[@data-field="productCode"])'));
        self::assertSame(1, $xpath->query('//form[@method="get"]//button[text()="Comparar versões"]')->length);
    }

    public function test_single_version_project_explains_when_comparison_becomes_available(): void
    {
        $data = $this->page();
        $data['project'] = '11111111-1111-4111-8111-111111111111';
        $data['currentRevision'] = 1;
        $data['versions'] = [[
            'revision' => 1, 'sha256' => str_repeat('a', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => false,
        ]];

        $html = view('preview.workspace', $data)->render();

        self::assertStringContainsString('A comparação estará disponível quando uma nova versão for criada.', $html);
        self::assertStringNotContainsString('name="compare_from"', $html);
        self::assertStringNotContainsString('Comparar com a anterior', $html);
        self::assertStringNotContainsString('data-testid="editorial-comparison"', $html);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }
}
