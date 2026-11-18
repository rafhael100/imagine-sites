<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewNavigation;
use App\Preview\PreviewBriefInput;
use App\Preview\PreviewComparisonInput;
use Imagine\Sites\Production\PreviewQueueQuery;
use Tests\TestCase;

final class PrivatePreviewWorkspaceViewTest extends TestCase
{
    private function page(): array
    {
        return [
            'projects' => [], 'project' => null, 'brief' => null,
            'versions' => [], 'reviews' => [], 'fieldErrors' => [],
            'currentReviews' => [], 'currentReviewsTruncated' => false, 'reviewSubmission' => null,
            'navigation' => PreviewNavigation::fromQuery([]),
            'currentRevision' => null, 'currentSha256' => null, 'queueNext' => null, 'comparison' => null,
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

    public function test_briefing_form_explains_codepoint_limits_without_utf16_truncation(): void
    {
        $data = $this->page();
        foreach (['brand' => 120, 'headline' => 180, 'description' => 600, 'call_to_action' => 80] as $field => $limit) {
            $data['form'][$field] = str_repeat('😀', $limit);
        }
        $data['form']['benefits'] = implode("\n", array_fill(0, 8, str_repeat('😀', 180)));
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        foreach (['brand' => 120, 'headline' => 180, 'description' => 600, 'call_to_action' => 80, 'benefits' => 1500] as $field => $limit) {
            $input = '//*[@id="'.$field.'"]';
            self::assertSame(1, $xpath->query($input.'/@required')->length);
            self::assertSame(0, $xpath->query($input.'/@maxlength')->length);
            self::assertSame('help-'.$field, $xpath->evaluate('string('.$input.'/@aria-describedby)'));
            self::assertStringContainsString((string) $limit, $xpath->evaluate('string(//*[@id="help-'.$field.'"])'));
            $value = in_array($field, ['description', 'benefits'], true) ? $input : $input.'/@value';
            self::assertSame($data['form'][$field], $xpath->evaluate('string('.$value.')'));
        }
        self::assertStringContainsString('Até 180 caracteres por benefício e 1500 no campo.', $html);
        self::assertStringContainsString('Os limites são conferidos ao enviar.', $html);
    }

    public function test_failed_briefing_keeps_valid_text_current_revision_and_navigation_context(): void
    {
        $data = $this->page();
        $data['project'] = '11111111-1111-4111-8111-111111111111';
        $data['currentRevision'] = 7;
        $data['navigation'] = PreviewNavigation::fromQuery(['status' => 'changes_requested', 'q' => 'Marca 😀'], true);
        $data['form'] = PreviewBriefInput::preserve([
            'product' => 'onepage', 'brand' => "Marca\0oculta", 'headline' => str_repeat('😀', 181),
            'description' => '<strong>Texto preservado</strong>', 'call_to_action' => 'Conheça',
            'benefits' => "Primeiro\nSegundo", 'expected_revision' => '2', 'company' => 'untrusted',
        ]);
        $data['fieldErrors'] = ['brand' => ['Digite novamente este campo.'], 'headline' => ['Use até 180 caracteres.']];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame('', $xpath->evaluate('string(//input[@name="brand"]/@value)'));
        self::assertSame($data['form']['headline'], $xpath->evaluate('string(//input[@name="headline"]/@value)'));
        self::assertSame($data['form']['description'], $xpath->evaluate('string(//textarea[@name="description"])'));
        self::assertSame($data['form']['benefits'], $xpath->evaluate('string(//textarea[@name="benefits"])'));
        self::assertSame('7', $xpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
        self::assertSame('help-brand error-brand', $xpath->evaluate('string(//input[@name="brand"]/@aria-describedby)'));
        self::assertSame('true', $xpath->evaluate('string(//input[@name="brand"]/@aria-invalid)'));
        self::assertSame($data['navigation']->projectUrl($data['project']), $xpath->evaluate('string(//form[@method="post"]/@action)'));
        self::assertSame(0, $xpath->query('//input[@name="company"]|//form[@method="post"]//input[@name="q"]')->length);
        self::assertStringNotContainsString("\0", $html);
        self::assertStringContainsString('&lt;strong&gt;Texto preservado&lt;/strong&gt;', $html);
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
        self::assertStringContainsString('<h3 id="version-title-20">Versão 20</h3>', $html);
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
        self::assertSame(0, $this->xpath($html)->query('//a[contains(@href,"compare_from=")]')->length);
        self::assertStringNotContainsString('data-testid="editorial-comparison"', $html);
    }

    public function test_recoverable_comparison_keeps_fields_context_and_current_briefing_without_a_result(): void
    {
        $data = $this->reviewPage();
        $data['canEdit'] = true;
        $data['currentRevision'] = 61;
        $data['form']['headline'] = 'Briefing atual da versão 61';
        $data['versions'][0]['revision'] = 40;
        $data['historyOlder'] = 21;
        $data['historyNewer'] = 40;
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.000000Z', $data['project']);
        $context = [
            'status' => 'changes_requested', 'format' => 'onepage', 'q' => 'Marca <fictícia> 😀',
            'cursor' => $cursor, 'before_revision' => '41',
        ];
        $data['navigation'] = PreviewNavigation::fromQuery($context, true);
        $data['comparisonInput'] = PreviewComparisonInput::recover(['compare_from' => '60', 'compare_to' => '1']);
        $xpath = $this->xpath(view('preview.workspace', $data)->render());
        $form = '//form[@data-testid="comparison-form"]';

        self::assertSame('get', $xpath->evaluate('string('.$form.'/@method)'));
        self::assertSame('60', $xpath->evaluate('string('.$form.'//input[@name="compare_from"]/@value)'));
        self::assertSame('1', $xpath->evaluate('string('.$form.'//input[@name="compare_to"]/@value)'));
        foreach (['compare_from', 'compare_to'] as $field) {
            $input = $form.'//input[@name="'.$field.'"]';
            self::assertSame('number', $xpath->evaluate('string('.$input.'/@type)'));
            self::assertSame('61', $xpath->evaluate('string('.$input.'/@max)'));
            self::assertSame('true', $xpath->evaluate('string('.$input.'/@aria-invalid)'));
            self::assertSame('comparison-help comparison-error', $xpath->evaluate('string('.$input.'/@aria-describedby)'));
        }
        self::assertSame('alert', $xpath->evaluate('string(//*[@data-testid="comparison-error"]/@role)'));
        self::assertStringContainsString('menor que a posterior', $xpath->evaluate('string(//*[@id="comparison-error"])'));
        self::assertSame(0, $xpath->query('//*[@data-testid="editorial-comparison"]')->length);
        self::assertSame('61', $xpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
        self::assertSame('Briefing atual da versão 61', $xpath->evaluate('string(//input[@name="headline"]/@value)'));
        self::assertSame(1, $xpath->query('//*[@data-version="40"]')->length);
        foreach ($context as $name => $value) {
            self::assertSame($value, $xpath->evaluate('string('.$form.'//input[@type="hidden"][@name="'.$name.'"]/@value)'));
        }
        self::assertSame($data['navigation']->projectUrl($data['project'], 41),
            $xpath->evaluate('string(//a[normalize-space(.)="Limpar comparação"]/@href)'));
        self::assertSame($data['navigation']->historyUrl($data['project'], 21),
            $xpath->evaluate('string(//a[normalize-space(.)="Versões anteriores →"]/@href)'));
        foreach ($xpath->query('//form[@method="post"]/@action') as $attribute) {
            parse_str(parse_url($attribute->value, PHP_URL_QUERY) ?? '', $parameters);
            self::assertSame($context, $parameters);
            self::assertArrayNotHasKey('compare_from', $parameters);
            self::assertArrayNotHasKey('compare_to', $parameters);
        }
        self::assertSame(0, $xpath->query('//form[@method="post"]//*[@name="compare_from" or @name="compare_to"]|//fictícia')->length);
    }

    public function test_empty_comparison_field_stays_empty_while_newer_history_context_is_preserved(): void
    {
        $data = $this->reviewPage();
        $data['currentRevision'] = 9;
        $data['navigation'] = PreviewNavigation::fromQuery(['after_revision' => '2', 'q' => 'Marca'], true);
        $data['comparisonInput'] = PreviewComparisonInput::recover(['compare_from' => null, 'compare_to' => '8']);
        $xpath = $this->xpath(view('preview.workspace', $data)->render());

        self::assertSame('', $xpath->evaluate('string(//input[@name="compare_from"]/@value)'));
        self::assertSame('8', $xpath->evaluate('string(//input[@name="compare_to"]/@value)'));
        self::assertSame('2', $xpath->evaluate('string(//form[@data-testid="comparison-form"]//input[@name="after_revision"]/@value)'));
        self::assertSame('Informe as duas versões para comparar.', $xpath->evaluate('string(//*[@data-testid="comparison-error"])'));
        self::assertSame($data['navigation']->projectUrl($data['project'], null, 2),
            $xpath->evaluate('string(//a[normalize-space(.)="Limpar comparação"]/@href)'));
        self::assertSame(0, $xpath->query('//*[@data-testid="editorial-comparison"]')->length);
    }

    public function test_single_version_error_has_preserved_fields_and_an_explicit_clear_link(): void
    {
        $data = $this->reviewPage();
        $data['currentRevision'] = 1;
        $data['versions'][0]['revision'] = 1;
        $data['comparisonInput'] = PreviewComparisonInput::recover(['compare_from' => '1', 'compare_to' => '1']);
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//form[@data-testid="comparison-form"]')->length);
        self::assertSame('1', $xpath->evaluate('string(//input[@name="compare_from"]/@value)'));
        self::assertSame('1', $xpath->evaluate('string(//input[@name="compare_to"]/@value)'));
        self::assertSame(1, $xpath->query('//*[@data-testid="comparison-error"]')->length);
        self::assertSame($data['navigation']->projectUrl($data['project']),
            $xpath->evaluate('string(//a[normalize-space(.)="Limpar comparação"]/@href)'));
        self::assertStringContainsString('A comparação estará disponível quando uma nova versão for criada.', $html);
        self::assertSame(0, $xpath->query('//a[normalize-space(.)="Comparar a versão atual com a anterior"]')->length);
        self::assertSame(0, $xpath->query('//*[@data-testid="editorial-comparison"]')->length);
    }

    public function test_review_form_explains_conditional_note_without_limiting_unicode_as_utf16(): void
    {
        $data = $this->reviewPage();
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $form = '//form[@class="review"]';

        self::assertSame('2', $xpath->evaluate('string('.$form.'/@data-review-revision)'));
        self::assertSame(str_repeat('b', 64), $xpath->evaluate('string('.$form.'/@data-review-digest)'));
        self::assertSame(str_repeat('b', 64), $xpath->evaluate('string('.$form.'//input[@name="sha256"]/@value)'));
        self::assertSame('changes_requested', $xpath->evaluate('string('.$form.'//select[@name="decision"]/option[@selected]/@value)'));
        self::assertSame('Orientação editorial da versão 2', $xpath->evaluate('string('.$form.'//label[@for="note-2"])'));
        self::assertSame('note-help-2', $xpath->evaluate('string('.$form.'//textarea[@name="note"]/@aria-describedby)'));
        self::assertSame(0, $xpath->query($form.'//textarea[@name="note"]/@maxlength|'.$form.'//textarea[@name="note"]/@required')->length);
        self::assertStringContainsString('Obrigatória ao solicitar ajustes; opcional ao aprovar internamente.', $html);
        self::assertSame(0, $xpath->query('//*[@data-testid="preserved-review"]')->length);
    }

    public function test_validation_keeps_note_decision_and_digest_on_only_the_original_version(): void
    {
        $data = $this->reviewPage();
        $data['currentRevision'] = 3;
        $data['currentSha256'] = str_repeat('c', 64);
        array_unshift($data['versions'], ['revision' => 3, 'sha256' => str_repeat('c', 64),
            'created_at' => '2026-11-17 13:00:00+00', 'reviewable' => true]);
        $note = "\n  </textarea><script data-review-probe=literal>Texto privado</script>\n".str_repeat('漢', 2001)."  \n";
        $data['reviewSubmission'] = [
            'revision' => 2, 'sha256' => str_repeat('b', 64), 'decision' => 'approved_internal',
            'note' => $note, 'canRetry' => true,
        ];
        $data['fieldErrors'] = ['note' => ['Use até 2000 caracteres na orientação editorial.']];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $preserved = '//form[@data-testid="preserved-review"]';

        foreach ([2, 3] as $revision) {
            $form = '//form[@class="review" and @data-review-revision="'.$revision.'"]';
            $titleId = 'version-title-'.$revision;
            self::assertSame(1, $xpath->query('//h3[@id="'.$titleId.'"]')->length);
            self::assertSame('Versão '.$revision, $xpath->evaluate('string(//h3[@id="'.$titleId.'"])'));
            self::assertSame($titleId, $xpath->evaluate('string('.$form.'/@aria-labelledby)'));
            self::assertSame('Parecer editorial da versão '.$revision,
                $xpath->evaluate('string('.$form.'//label[@for="decision-'.$revision.'"])'));
            self::assertSame('Orientação editorial da versão '.$revision,
                $xpath->evaluate('string('.$form.'//label[@for="note-'.$revision.'"])'));
            self::assertSame('Registrar revisão editorial da versão '.$revision,
                $xpath->evaluate('string('.$form.'//button[@type="submit"])'));
        }
        self::assertSame(1, $xpath->query('//h3[@id="version-title-3"]/following-sibling::span[@class="status" and normalize-space(.)="Atual"]')->length);
        self::assertSame('Confira o parecer da versão 2', $xpath->evaluate('string(//*[@id="errors-title"])'));
        self::assertSame('errors-title', $xpath->evaluate('string(//*[@role="alert"]/@aria-labelledby)'));
        self::assertSame(1, $xpath->query($preserved)->length);
        self::assertSame('2', $xpath->evaluate('string('.$preserved.'/@data-review-revision)'));
        self::assertSame(str_repeat('b', 64), $xpath->evaluate('string('.$preserved.'/@data-review-digest)'));
        self::assertSame('/_preview/projects/'.$data['project'].'/versions/2/review', $xpath->evaluate('string('.$preserved.'/@action)'));
        self::assertSame('approved_internal', $xpath->evaluate('string('.$preserved.'//select/option[@selected]/@value)'));
        // libxml retains the leading LF which browser HTML parsing discards for textarea.
        self::assertSame("\n".$note, $xpath->evaluate('string('.$preserved.'//textarea[@name="note"])'));
        self::assertSame('true', $xpath->evaluate('string('.$preserved.'//textarea/@aria-invalid)'));
        self::assertSame('note-help-2 error-note-2', $xpath->evaluate('string('.$preserved.'//textarea/@aria-describedby)'));
        self::assertSame('', trim($xpath->evaluate('string(//form[@data-review-revision="3"]//textarea)')));
        self::assertSame('changes_requested', $xpath->evaluate('string(//form[@data-review-revision="3"]//select/option[@selected]/@value)'));
        self::assertSame(0, $xpath->query('//form[@data-review-revision="3"]//*[@aria-invalid="true"]')->length);
        self::assertSame(0, $xpath->query('//script|//*[@data-review-probe]')->length);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script data-review-probe=literal&gt;', $html);
    }

    public function test_conflict_shows_preserved_input_without_resending_or_replacing_the_existing_review(): void
    {
        $data = $this->reviewPage();
        $data['versions'][0]['reviewable'] = false;
        $data['currentRevision'] = 3;
        $data['currentSha256'] = str_repeat('c', 64);
        array_unshift($data['versions'], ['revision' => 3, 'sha256' => str_repeat('c', 64),
            'created_at' => '2026-11-17 13:00:00+00', 'reviewable' => true]);
        $note = "  Texto que não substitui o parecer anterior.\n<em>Orientação preservada</em>  ";
        $data['reviewSubmission'] = [
            'revision' => 2, 'sha256' => str_repeat('b', 64), 'decision' => 'changes_requested',
            'note' => $note, 'canRetry' => false,
        ];
        $data['fieldErrors'] = ['review' => ['Seu acesso já registrou um parecer para esta versão.']];
        $data['reviews'] = [[
            'revision' => 2, 'sha256' => str_repeat('b', 64), 'decision' => 'approved_internal',
            'note' => 'Parecer anterior original', 'created_at' => '2026-11-17 12:30:00+00',
        ]];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $preserved = '//*[@data-testid="preserved-review"]';

        self::assertSame('2', $xpath->evaluate('string('.$preserved.'/@data-review-revision)'));
        self::assertSame(str_repeat('b', 64), $xpath->evaluate('string('.$preserved.'/@data-review-digest)'));
        self::assertSame($note, $xpath->evaluate('string('.$preserved.'//*[@data-review-note])'));
        self::assertSame(0, $xpath->query($preserved.'//form|'.$preserved.'//button|//form[@data-review-revision="2"]')->length);
        self::assertSame('', trim($xpath->evaluate('string(//form[@data-review-revision="3"]//textarea)')));
        self::assertSame('Parecer anterior original', $xpath->evaluate('string(//*[@data-testid="editorial-review-history"]//*[@data-review-note])'));
        self::assertSame('Confira o parecer da versão 2', $xpath->evaluate('string(//*[@id="errors-title"])'));
        self::assertSame('Parecer não registrado da versão 2', $xpath->evaluate('string('.$preserved.'/h2[@id="preserved-review-title"])'));
        self::assertStringContainsString('Solicitar ajustes', $xpath->evaluate('string('.$preserved.')'));
        self::assertSame(0, $xpath->query('//em')->length);
    }

    public function test_failed_review_outside_the_history_window_is_preserved_without_a_new_target(): void
    {
        $data = $this->reviewPage();
        $data['navigation'] = PreviewNavigation::fromQuery(['before_revision' => '21'], true);
        $data['versions'] = [['revision' => 20, 'sha256' => str_repeat('a', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => true]];
        $data['currentRevision'] = 61;
        $data['currentSha256'] = str_repeat('c', 64);
        $data['reviewSubmission'] = [
            'revision' => 60, 'sha256' => str_repeat('b', 64), 'decision' => 'approved_internal',
            'note' => "Texto original da versão 60\n", 'canRetry' => false,
        ];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $preserved = '//*[@data-testid="preserved-review"]';

        self::assertSame('60', $xpath->evaluate('string('.$preserved.'/@data-review-revision)'));
        self::assertSame('Parecer não registrado da versão 60', $xpath->evaluate('string('.$preserved.'/h2[@id="preserved-review-title"])'));
        self::assertSame("Texto original da versão 60\n", $xpath->evaluate('string('.$preserved.'//*[@data-review-note])'));
        self::assertSame(0, $xpath->query($preserved.'//button|//form[@data-review-revision="60"]')->length);
        self::assertSame('', trim($xpath->evaluate('string(//form[@data-review-revision="20"]//textarea)')));
        self::assertStringContainsString('A orientação não será reaplicada a outra versão.', $html);
    }

    public function test_current_guidance_is_independent_from_older_history_and_comparison(): void
    {
        $data = $this->reviewPage();
        $data['canEdit'] = true;
        $data['canReview'] = false;
        $data['navigation'] = PreviewNavigation::fromQuery(['before_revision' => '21', 'compare_from' => '1', 'compare_to' => '60'], true);
        $data['versions'] = [['revision' => 20, 'sha256' => str_repeat('a', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => true]];
        $data['currentRevision'] = 61;
        $data['currentSha256'] = str_repeat('c', 64);
        $currentNote = "  <img src=x onerror=alert(1)>\nOrientação da versão atual 😀\n".str_repeat('字', 300)."  ";
        $data['currentReviews'] = [[
            'revision' => 61, 'sha256' => str_repeat('c', 64), 'decision' => 'changes_requested',
            'note' => $currentNote, 'created_at' => '2026-11-17 13:00:00+00',
        ]];
        $data['reviews'] = [[
            'revision' => 20, 'sha256' => str_repeat('a', 64), 'decision' => 'changes_requested',
            'note' => 'Orientação histórica da versão 20', 'created_at' => '2026-11-17 12:30:00+00',
        ]];
        $brief = ['productCode' => 'onepage', 'brand' => 'Marca', 'headline' => 'Chamada',
            'description' => 'Descrição', 'callToAction' => 'Conheça', 'benefits' => ['Benefício']];
        $data['comparison'] = [
            'from' => ['revision' => 1, 'sha256' => str_repeat('a', 64), 'created_at' => '2026-11-16 12:00:00+00', 'brief' => $brief],
            'to' => ['revision' => 60, 'sha256' => str_repeat('b', 64), 'created_at' => '2026-11-17 12:00:00+00', 'brief' => $brief],
            'changed_fields' => [],
        ];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $current = '//*[@data-testid="current-editorial-guidance"]';
        $history = '//*[@data-testid="editorial-review-history"]';

        self::assertSame('61', $xpath->evaluate('string('.$current.'/@data-review-revision)'));
        self::assertSame(str_repeat('c', 64), $xpath->evaluate('string('.$current.'/@data-review-digest)'));
        self::assertSame($currentNote, $xpath->evaluate('string('.$current.'//*[@data-review-note])'));
        self::assertSame('61', $xpath->evaluate('string('.$current.'//article/@data-review-revision)'));
        self::assertSame('Orientação histórica da versão 20', $xpath->evaluate('string('.$history.'//*[@data-review-note])'));
        self::assertSame('20', $xpath->evaluate('string('.$history.'//article/@data-review-revision)'));
        self::assertSame('61', $xpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
        self::assertSame('1', $xpath->evaluate('string(//*[@data-compare-side="from"]/@data-compare-revision)'));
        self::assertSame('60', $xpath->evaluate('string(//*[@data-compare-side="to"]/@data-compare-revision)'));
        self::assertSame(0, $xpath->query('//img|//script|//form[@class="review"]')->length);
        self::assertStringContainsString('white-space:pre-wrap;overflow-wrap:anywhere', $html);
    }

    public function test_reader_can_see_legacy_and_optional_empty_notes_and_separate_truncation_notices(): void
    {
        $data = $this->reviewPage();
        $data['canReview'] = false;
        $data['currentReviews'] = [[
            'revision' => 2, 'sha256' => str_repeat('b', 64), 'decision' => 'approved_internal',
            'note' => null, 'created_at' => '2026-11-17 13:00:00+00',
        ]];
        $data['reviews'] = [[
            'revision' => 1, 'sha256' => str_repeat('a', 64), 'decision' => 'changes_requested',
            'note' => null, 'created_at' => '2026-11-17 12:30:00+00',
        ]];
        $data['currentReviewsTruncated'] = true;
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(2, substr_count($html, 'Este parecer foi registrado sem observação.'));
        self::assertStringContainsString('Mostrando os 100 pareceres mais recentes da versão atual. Use o link de consulta acima para ver os demais pareceres.', $html);
        self::assertStringNotContainsString('Este resumo está limitado a 100 pareceres.', $html);
        self::assertSame(0, $xpath->query('//form[@method="post"]|//*[@data-review-note]')->length);
        self::assertStringContainsString('Versão 1: Ajustes solicitados', $html);
        $data['currentReviewsTruncated'] = false;
        $data['reviewsTruncated'] = true;
        $html = view('preview.workspace', $data)->render();
        self::assertStringNotContainsString('Mostrando os 100 pareceres mais recentes da versão atual.', $html);
        self::assertStringContainsString('Este resumo está limitado a 100 pareceres. Use “Consultar pareceres da versão” no cartão desejado para continuar a consulta.', $html);
    }

    public function test_queue_search_is_escaped_accessible_and_resets_pagination_when_submitted(): void
    {
        $data = $this->page();
        $search = '\"><img src=x onerror=alert(1)> 100%_\\';
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.000000Z', '11111111-1111-4111-8111-111111111111');
        $data['navigation'] = PreviewNavigation::fromQuery([
            'status' => 'changes_requested', 'format' => 'onepage', 'q' => $search, 'cursor' => $cursor,
        ]);
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $form = '//form[@data-testid="queue-filters"]';
        $input = $form.'//input[@name="q"]';

        self::assertSame('get', $xpath->evaluate('string('.$form.'/@method)'));
        self::assertSame('/_preview', $xpath->evaluate('string('.$form.'/@action)'));
        self::assertSame('Buscar projeto', $xpath->evaluate('string('.$form.'//label[@for="queue-search"])'));
        self::assertSame('search', $xpath->evaluate('string('.$input.'/@type)'));
        self::assertSame($search, $xpath->evaluate('string('.$input.'/@value)'));
        self::assertSame('queue-search-help', $xpath->evaluate('string('.$input.'/@aria-describedby)'));
        self::assertSame(0, $xpath->query($input.'/@maxlength|'.$form.'//input[@name="cursor"]|//img|//script')->length);
        self::assertSame('/_preview?status=changes_requested&format=onepage',
            $xpath->evaluate('string(//a[normalize-space(.)="Limpar busca"]/@href)'));
        self::assertStringContainsString($search, $xpath->evaluate('string(//*[@data-testid="queue-empty"])'));
        self::assertSame(1, $xpath->query('//*[@data-testid="preview-queue"]')->length);
        self::assertSame(0, $xpath->query('//form[@method="post"]//*[@name="q"]')->length);
    }

    public function test_search_context_is_rendered_in_get_forms_and_actions_but_never_post_fields(): void
    {
        $data = $this->reviewPage();
        $data['canEdit'] = true;
        $data['navigation'] = PreviewNavigation::fromQuery([
            'q' => 'Marca ç 😀', 'status' => 'awaiting_review', 'before_revision' => '3',
        ], true);
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame('Marca ç 😀', $xpath->evaluate('string(//form[@method="get"]//input[@name="q"]/@value)'));
        self::assertSame(0, $xpath->query('//form[@method="post"]//*[@name="q"]')->length);
        self::assertSame(2, $xpath->query('//form[@method="post"]')->length);
        foreach ($xpath->query('//form[@method="post"]/@action') as $attribute) {
            parse_str(parse_url($attribute->value, PHP_URL_QUERY) ?? '', $parameters);
            self::assertSame('Marca ç 😀', $parameters['q']);
            self::assertSame('awaiting_review', $parameters['status']);
        }
        self::assertSame(0, $xpath->query('//*[@data-testid="preview-queue"]')->length);
    }

    public function test_empty_and_maximum_unicode_search_render_without_a_utf16_limit(): void
    {
        $data = $this->page();
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        self::assertSame('', $xpath->evaluate('string(//input[@name="q"]/@value)'));
        self::assertSame(0, $xpath->query('//a[normalize-space(.)="Limpar busca"]')->length);
        self::assertStringContainsString('Nenhum projeto encontrado nesta página com os filtros escolhidos.', $html);

        $data['navigation'] = PreviewNavigation::fromQuery(['q' => str_repeat('😀', 100)]);
        $xpath = $this->xpath(view('preview.workspace', $data)->render());
        self::assertSame(str_repeat('😀', 100), $xpath->evaluate('string(//input[@name="q"]/@value)'));
        self::assertSame(0, $xpath->query('//input[@name="q"]/@maxlength')->length);
    }

    private function reviewPage(): array
    {
        $data = $this->page();
        $data['project'] = '11111111-1111-4111-8111-111111111111';
        $data['currentRevision'] = 2;
        $data['currentSha256'] = str_repeat('b', 64);
        $data['canEdit'] = false;
        $data['canReview'] = true;
        $data['versions'] = [[
            'revision' => 2, 'sha256' => str_repeat('b', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => true,
        ]];
        return $data;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }
}
