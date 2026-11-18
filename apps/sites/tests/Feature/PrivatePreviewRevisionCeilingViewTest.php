<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewBriefInput;
use App\Preview\PreviewNavigation;
use Imagine\Sites\Production\PreviewQueueQuery;
use Tests\TestCase;

final class PrivatePreviewRevisionCeilingViewTest extends TestCase
{
    public function test_versions_above_the_old_ceiling_and_the_last_appendable_head_keep_the_form(): void
    {
        foreach ([10001, PreviewBriefInput::MAX_EXPECTED_REVISION] as $revision) {
            $data = $this->page($revision);
            $data['canAppendVersion'] = true;
            $html = view('preview.workspace', $data)->render();
            $xpath = $this->xpath($html);

            self::assertSame(1, $xpath->query('//form[@data-testid="briefing-form"]')->length);
            self::assertSame((string) $revision, $xpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
            self::assertSame($data['form']['headline'], $xpath->evaluate('string(//input[@name="headline"]/@value)'));
            self::assertSame(1, $xpath->query('//button[contains(.,"Gerar nova versão privada")]')->length);
            self::assertSame(0, $xpath->query('//*[@data-testid="revision-limit-notice" or @data-testid="briefing-readonly"]')->length);
            self::assertStringContainsString('Este briefing corresponde à versão atual '.$revision.'.', $html);
            self::assertSame($data['navigation']->projectUrl($data['project']),
                $xpath->evaluate('string(//form[@data-testid="briefing-form"]/@action)'));
        }
    }

    public function test_terminal_author_keeps_reading_comparison_history_and_editorial_review_without_an_append_form(): void
    {
        $maximum = 2147483647;
        $data = $this->page($maximum);
        $data['canAppendVersion'] = false;
        $data['canReview'] = true;
        $data['form']['headline'] = $data['brief']['headline'] = '<strong data-terminal-probe="literal">Briefing salvo final</strong>';
        $data['historyOlder'] = $maximum - 1;
        $data['versions'][] = $this->version($maximum - 1, str_repeat('b', 64));
        $cursor = PreviewQueueQuery::cursorFor('2026-11-18T12:00:00.000000Z', $data['project']);
        $data['navigation'] = PreviewNavigation::fromQuery([
            'status' => 'changes_requested', 'format' => 'onepage', 'q' => 'Busca atual', 'cursor' => $cursor,
            'compare_from' => (string) ($maximum - 1), 'compare_to' => (string) $maximum,
        ], true);
        $before = $data['brief'];
        $before['headline'] = 'Chamada anterior';
        $data['comparison'] = [
            'from' => $this->version($maximum - 1, str_repeat('b', 64)) + ['brief' => $before],
            'to' => $this->version($maximum, $data['currentSha256']) + ['brief' => $data['brief']],
            'changed_fields' => ['headline'],
        ];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $readonly = '//*[@data-testid="briefing-readonly"]';
        $review = '//form[@class="review" and @data-review-revision="2147483647"]';

        self::assertSame('Limite de versões atingido.',
            $xpath->evaluate('string(//*[@data-testid="revision-limit-notice"]/strong)'));
        self::assertSame('note', $xpath->evaluate('string(//*[@data-testid="revision-limit-notice"]/@role)'));
        self::assertStringContainsString('A revisão editorial continua disponível de acordo com seu acesso.', $html);
        self::assertSame(1, $xpath->query('//h2[normalize-space(.)="Briefing da versão atual"]')->length);
        self::assertSame(0, $xpath->query('//form[@data-testid="briefing-form"]|//input[@name="expected_revision"]|//input[@name="headline"]')->length);
        self::assertStringNotContainsString('Gerar nova versão privada', $html);
        self::assertStringNotContainsString('A geração de versões está disponível ao responsável pela edição.', $html);
        self::assertSame(6, $xpath->query($readonly.'/dd[@data-field]')->length);
        self::assertSame('Site Onepage', $xpath->evaluate('string('.$readonly.'/dd[@data-field="product"])'));
        foreach (['brand', 'headline', 'description', 'call_to_action', 'benefits'] as $field) {
            self::assertSame($data['form'][$field], $xpath->evaluate('string('.$readonly.'/dd[@data-field="'.$field.'"])'));
        }
        self::assertSame(0, $xpath->query('//*[@data-terminal-probe]')->length);
        self::assertSame(1, $xpath->query('//*[@data-testid="editorial-comparison"]')->length);
        self::assertSame((string) ($maximum - 1), $xpath->evaluate('string(//input[@name="compare_from"]/@value)'));
        self::assertSame((string) $maximum, $xpath->evaluate('string(//input[@name="compare_to"]/@value)'));
        self::assertSame((string) $maximum, $xpath->evaluate('string(//input[@name="compare_to"]/@max)'));
        self::assertSame('get', $xpath->evaluate('string(//form[@data-testid="comparison-form"]/@method)'));
        self::assertSame(1, $xpath->query('//a[@href="/_preview/projects/'.$data['project'].'/versions/2147483647/html"]')->length);
        self::assertSame($data['navigation']->historyUrl($data['project'], $maximum - 1),
            $xpath->evaluate('string(//a[normalize-space(.)="Versões anteriores →"]/@href)'));
        self::assertSame($data['navigation']->queueUrl(), $xpath->evaluate('string(//header/a/@href)'));
        self::assertSame($data['navigation']->reviewUrl($data['project'], $maximum), $xpath->evaluate('string('.$review.'/@action)'));
        self::assertSame($data['currentSha256'], $xpath->evaluate('string('.$review.'//input[@name="sha256"]/@value)'));
        self::assertSame(1, $xpath->query($review.'//button[normalize-space(.)="Registrar revisão editorial"]')->length);
        self::assertSame(0, $xpath->query($review.'//*[@name="expected_revision" or @name="compare_from" or @name="compare_to"]')->length);
        self::assertStringContainsString('O briefing salvo da versão atual 2147483647 continua disponível para consulta.', $html);
        self::assertStringNotContainsString('A comparação mantém o formulário de edição na versão atual', $html);
    }

    public function test_an_old_history_window_does_not_reenable_append_after_the_head_reaches_the_limit(): void
    {
        $maximum = 2147483647;
        $data = $this->page($maximum);
        $data['canAppendVersion'] = false;
        $data['navigation'] = PreviewNavigation::fromQuery(['before_revision' => (string) $maximum], true);
        $data['historyNewer'] = $maximum - 1;
        $data['versions'] = [$this->version($maximum - 1, str_repeat('b', 64))];
        $data['form']['headline'] = 'Briefing salvo da head terminal, fora desta janela';
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//*[@data-testid="revision-limit-notice"]')->length);
        self::assertSame(0, $xpath->query('//form[@data-testid="briefing-form"]|//*[@data-version="2147483647"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-version="2147483646"]')->length);
        self::assertSame($data['form']['headline'],
            $xpath->evaluate('string(//*[@data-testid="briefing-readonly"]/dd[@data-field="headline"])'));
        self::assertSame($data['navigation']->historyUrl($data['project']),
            $xpath->evaluate('string(//a[normalize-space(.)="Ir à versão atual"]/@href)'));
        self::assertStringContainsString('O briefing salvo da versão atual continua disponível para consulta.', $html);
        self::assertStringNotContainsString('O briefing de edição continua na versão atual.', $html);
    }

    public function test_terminal_failed_input_stays_separate_from_saved_data_without_promising_another_submission(): void
    {
        $data = $this->page(2147483647);
        $data['canAppendVersion'] = false;
        $data['hasUnsavedBrief'] = true;
        $data['fieldErrors'] = ['expected_revision' => ['Confira a versão atual.']];
        $data['form']['headline'] = '<script data-terminal-attempt="literal">Tentativa preservada</script>';
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//*[@data-testid="revision-limit-notice"]')->length);
        self::assertSame(1, $xpath->query('//h2[normalize-space(.)="Briefing ainda não salvo"]')->length);
        self::assertStringContainsString('O texto da tentativa foi mantido para consulta.',
            $xpath->evaluate('string(//*[@data-testid="unsaved-brief-notice"])'));
        self::assertStringNotContainsString('enviar novamente', $html);
        self::assertSame(0, $xpath->query('//form[@data-testid="briefing-form"]|//input[@name="expected_revision"]|//script|//*[@data-terminal-attempt]')->length);
        self::assertSame($data['form']['headline'],
            $xpath->evaluate('string(//*[@data-testid="briefing-readonly"]/dd[@data-field="headline"])'));
        self::assertSame($data['brief']['headline'],
            $xpath->evaluate('string(//*[@data-testid="saved-current-brief"]//dd[@data-field="headline"])'));
        self::assertSame('2147483647', $xpath->evaluate('string(//*[@data-testid="saved-current-brief"]/@data-brief-revision)'));
        self::assertSame($data['currentSha256'], $xpath->evaluate('string(//*[@data-testid="saved-current-brief"]/@data-brief-digest)'));
        self::assertSame(0, $xpath->query('//*[@data-testid="saved-current-brief"]/@open')->length);
    }

    public function test_absent_edit_permission_does_not_falsely_announce_a_revision_limit(): void
    {
        $data = $this->page(7);
        $data['canEdit'] = false;
        $data['canAppendVersion'] = false;
        $data['canReview'] = true;
        $xpath = $this->xpath(view('preview.workspace', $data)->render());

        self::assertSame(0, $xpath->query('//*[@data-testid="revision-limit-notice"]|//form[@data-testid="briefing-form"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-testid="briefing-readonly"]')->length);
        self::assertSame(1, $xpath->query('//form[@class="review"]')->length);
        self::assertSame($data['navigation']->reviewUrl($data['project'], 7),
            $xpath->evaluate('string(//form[@class="review"]/@action)'));
    }

    public function test_terminal_reviewer_does_not_receive_a_false_promise_of_generation_by_an_editor(): void
    {
        $data = $this->page(2147483647);
        $data['canEdit'] = false;
        $data['canAppendVersion'] = false;
        $data['canReview'] = true;
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//*[@data-testid="revision-limit-notice"]')->length);
        self::assertSame(0, $xpath->query('//form[@data-testid="briefing-form"]|//input[@name="expected_revision"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-testid="briefing-readonly"]')->length);
        self::assertSame(1, $xpath->query('//form[@class="review" and @data-review-revision="2147483647"]')->length);
        self::assertSame(1, $xpath->query('//form[@data-testid="comparison-form"]')->length);
        self::assertStringNotContainsString('A geração de versões está disponível ao responsável pela edição.', $html);
        self::assertStringNotContainsString('A comparação mantém o formulário de edição na versão atual', $html);
        self::assertStringContainsString('O briefing salvo da versão atual 2147483647 continua disponível para consulta.', $html);
    }

    public function test_new_project_creation_keeps_its_ordinary_form(): void
    {
        $data = array_replace($this->page(7), [
            'project' => null, 'brief' => null, 'versions' => [],
            'currentRevision' => null, 'currentSha256' => null,
            'navigation' => PreviewNavigation::fromQuery(['status' => 'awaiting_review', 'format' => 'onepage']),
        ]);
        $data['form'] = ['product' => 'onepage', 'brand' => '', 'headline' => '', 'description' => '',
            'call_to_action' => '', 'benefits' => ''];
        $xpath = $this->xpath(view('preview.workspace', $data)->render());

        self::assertSame(1, $xpath->query('//form[@data-testid="briefing-form"]')->length);
        self::assertSame($data['navigation']->createUrl(), $xpath->evaluate('string(//form[@data-testid="briefing-form"]/@action)'));
        self::assertSame(1, $xpath->query('//button[contains(.,"Criar demonstração")]')->length);
        self::assertSame(0, $xpath->query('//*[@data-testid="revision-limit-notice" or @data-testid="briefing-readonly"]|//input[@name="expected_revision"]')->length);
    }

    private function page(int $revision): array
    {
        $brief = ['productCode' => 'onepage', 'brand' => 'Marca salva', 'headline' => 'Chamada salva',
            'description' => "Descrição salva\nSegunda linha", 'callToAction' => 'Botão salvo',
            'benefits' => ['Primeiro benefício', 'Segundo benefício']];
        return [
            'projects' => [], 'project' => '11111111-1111-4111-8111-111111111111', 'brief' => $brief,
            'versions' => [$this->version($revision, str_repeat('a', 64))], 'reviews' => [], 'fieldErrors' => [],
            'currentReviews' => [], 'currentReviewsTruncated' => false, 'reviewSubmission' => null,
            'navigation' => PreviewNavigation::fromQuery([], true),
            'currentRevision' => $revision, 'currentSha256' => str_repeat('a', 64), 'queueNext' => null, 'comparison' => null,
            'historyOlder' => null, 'historyNewer' => null, 'reviewsTruncated' => false,
            'form' => ['product' => 'onepage', 'brand' => $brief['brand'], 'headline' => $brief['headline'],
                'description' => $brief['description'], 'call_to_action' => $brief['callToAction'],
                'benefits' => implode("\n", $brief['benefits'])],
            'canEdit' => true, 'canReview' => false, 'canAppendVersion' => true, 'hasUnsavedBrief' => false,
        ];
    }

    private function version(int $revision, string $digest): array
    {
        return ['revision' => $revision, 'sha256' => $digest,
            'created_at' => '2026-11-18 12:00:00+00', 'reviewable' => true];
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }
}
