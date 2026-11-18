<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewNavigation;
use Imagine\Sites\Production\PreviewQueueQuery;
use Tests\TestCase;

final class PrivatePreviewUnsavedBriefViewTest extends TestCase
{
    public function test_conflict_separates_the_escaped_saved_head_from_the_unsaved_form_and_keeps_context(): void
    {
        $data = $this->page();
        $data['hasUnsavedBrief'] = true;
        $data['fieldErrors'] = ['expected_revision' => ['Outra versão foi criada. Seu briefing foi mantido.']];
        $data['brief'] = [
            'productCode' => 'onepage', 'brand' => 'Marca salva & "corrente"',
            'headline' => '<script data-saved-probe="literal">Chamada salva</script>',
            'description' => "Descrição salva\nSegunda linha 😀", 'callToAction' => '<strong>Botão salvo</strong>',
            'benefits' => ['Segundo na tentativa, primeiro salvo', '<img data-saved-probe="benefit" src=x>'],
        ];
        $data['form'] = [
            'product' => 'onepage', 'brand' => 'Marca da tentativa',
            'headline' => '"><script data-draft-probe="literal">Tentativa</script>',
            'description' => "Descrição ainda não salva\nLinha preservada 😀", 'call_to_action' => 'Botão da tentativa',
            'benefits' => "Primeiro da tentativa\nSegundo da tentativa",
        ];
        $cursor = PreviewQueueQuery::cursorFor('2026-11-18T12:00:00.000000Z', $data['project']);
        $data['navigation'] = PreviewNavigation::fromQuery([
            'status' => 'changes_requested', 'format' => 'onepage', 'q' => 'Marca "seleção"',
            'cursor' => $cursor, 'before_revision' => '4',
        ], true);
        $data['historyOlder'] = 3;
        $data['historyNewer'] = 3;
        $data['versions'] = [['revision' => 3, 'sha256' => str_repeat('b', 64),
            'created_at' => '2026-11-17 12:00:00+00', 'reviewable' => false]];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $saved = '//*[@data-testid="saved-current-brief"]';
        $form = '//form[@data-testid="briefing-form"]';

        self::assertSame(1, $xpath->query('//*[@data-testid="unsaved-brief-notice"]')->length);
        self::assertStringContainsString('Este briefing ainda não foi salvo.',
            $xpath->evaluate('string(//*[@id="unsaved-brief-notice"])'));
        self::assertSame('unsaved-brief-notice', $xpath->evaluate('string('.$form.'/@aria-describedby)'));
        self::assertSame(1, $xpath->query('//h2[normalize-space(.)="Briefing ainda não salvo"]')->length);
        self::assertSame(1, $xpath->query('//details[@data-testid="saved-current-brief"]')->length);
        self::assertSame(0, $xpath->query($saved.'/@open')->length, 'The native disclosure starts closed');
        self::assertSame('Consultar briefing salvo da versão 7', $xpath->evaluate('string('.$saved.'/summary)'));
        self::assertSame('7', $xpath->evaluate('string('.$saved.'/@data-brief-revision)'));
        self::assertSame($data['currentSha256'], $xpath->evaluate('string('.$saved.'/@data-brief-digest)'));
        self::assertSame('SHA-256: '.$data['currentSha256'], $xpath->evaluate('string('.$saved.'/code)'));
        self::assertSame(6, $xpath->query($saved.'//dd[@data-field]')->length);
        self::assertSame('Site Onepage', $xpath->evaluate('string('.$saved.'//dd[@data-field="productCode"])'));
        foreach (['brand', 'headline', 'description', 'callToAction'] as $field) {
            self::assertSame($data['brief'][$field], $xpath->evaluate('string('.$saved.'//dd[@data-field="'.$field.'"])'));
        }
        self::assertSame($data['brief']['benefits'], array_map(
            static fn (\DOMNode $node): string => $node->textContent,
            iterator_to_array($xpath->query($saved.'//dd[@data-field="benefits"]/ol/li')),
        ));
        self::assertSame(0, $xpath->query($saved.'//input|'.$saved.'//textarea|'.$saved.'//button|'.$saved.'//form|'.$saved.'//a')->length,
            'Inspecting the saved snapshot introduces no submission or navigation');
        foreach (['brand', 'headline', 'call_to_action'] as $field) {
            self::assertSame($data['form'][$field], $xpath->evaluate('string('.$form.'//input[@name="'.$field.'"]/@value)'));
        }
        foreach (['description', 'benefits'] as $field) {
            self::assertSame($data['form'][$field], $xpath->evaluate('string('.$form.'//textarea[@name="'.$field.'"])'));
        }
        self::assertSame('7', $xpath->evaluate('string('.$form.'//input[@name="expected_revision"]/@value)'));
        self::assertSame('onepage', $xpath->evaluate('string('.$form.'//input[@name="product"]/@value)'));
        self::assertSame($data['navigation']->projectUrl($data['project'], 4), $xpath->evaluate('string('.$form.'/@action)'));
        self::assertSame($data['navigation']->queueUrl(), $xpath->evaluate('string(//header/a/@href)'));
        self::assertSame(0, $xpath->query($form.'//*[@name="q" or @name="cursor" or @name="compare_from" or @name="compare_to"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-version="3"]')->length);
        self::assertSame(0, $xpath->query('//*[@data-version="7"]')->length,
            'The saved snapshot is the head even when it is outside the history window');
        self::assertSame(0, $xpath->query('//script|//img|//*[@data-saved-probe or @data-draft-probe or @onclick]')->length);
        self::assertStringContainsString('&lt;script data-saved-probe=&quot;literal&quot;&gt;', $html);
        self::assertStringContainsString('&lt;script data-draft-probe=&quot;literal&quot;&gt;', $html);
        foreach (['Este briefing corresponde à versão atual', 'A comparação mantém o formulário de edição na versão atual',
            'O briefing de edição continua na versão atual.'] as $incorrectClaim) {
            self::assertStringNotContainsString($incorrectClaim, $html);
        }
    }

    public function test_failed_validation_keeps_field_guidance_and_the_saved_snapshot_separate(): void
    {
        $data = $this->page();
        $data['hasUnsavedBrief'] = true;
        $data['form']['headline'] = str_repeat('😀', 181);
        $data['fieldErrors'] = ['headline' => ['Use até 180 caracteres.']];
        $xpath = $this->xpath(view('preview.workspace', $data)->render());

        self::assertSame($data['form']['headline'], $xpath->evaluate('string(//input[@id="headline"]/@value)'));
        self::assertSame($data['brief']['headline'],
            $xpath->evaluate('string(//*[@data-testid="saved-current-brief"]//dd[@data-field="headline"])'));
        self::assertSame('true', $xpath->evaluate('string(//input[@id="headline"]/@aria-invalid)'));
        self::assertSame('help-headline error-headline', $xpath->evaluate('string(//input[@id="headline"]/@aria-describedby)'));
        self::assertSame('Use até 180 caracteres.', $xpath->evaluate('string(//*[@id="error-headline"])'));
        self::assertSame(1, $xpath->query('//*[@role="alert"]')->length);
        self::assertSame(0, $xpath->query('//input[@id="headline"]/@maxlength')->length);
        self::assertSame('7', $xpath->evaluate('string(//input[@name="expected_revision"]/@value)'));
    }

    public function test_new_project_error_identifies_unsaved_text_without_inventing_a_saved_head(): void
    {
        $data = $this->newProject();
        $data['hasUnsavedBrief'] = true;
        $data['form']['brand'] = 'Marca da criação ainda não salva';
        $data['fieldErrors'] = ['benefits' => ['Informe de um a oito benefícios.']];
        $data['navigation'] = PreviewNavigation::fromQuery(['status' => 'changes_requested', 'q' => 'Busca atual']);
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//*[@data-testid="unsaved-brief-notice"]')->length);
        self::assertSame(0, $xpath->query('//*[@data-testid="saved-current-brief"]|//input[@name="expected_revision"]')->length);
        self::assertSame($data['form']['brand'], $xpath->evaluate('string(//input[@name="brand"]/@value)'));
        self::assertSame('unsaved-brief-notice', $xpath->evaluate('string(//form[@data-testid="briefing-form"]/@aria-describedby)'));
        self::assertSame($data['navigation']->createUrl(), $xpath->evaluate('string(//form[@data-testid="briefing-form"]/@action)'));
        self::assertSame(1, $xpath->query('//form[@data-testid="briefing-form"]//button[contains(.,"Criar demonstração")]')->length);
        self::assertStringNotContainsString('Consultar briefing salvo da versão', $html);
    }

    public function test_read_only_recovery_labels_the_preserved_attempt_and_still_exposes_the_saved_head(): void
    {
        $data = $this->page();
        $data['hasUnsavedBrief'] = true;
        $data['canEdit'] = false;
        $data['form']['headline'] = 'Tentativa preservada após perda de edição';
        $data['fieldErrors'] = ['expected_revision' => ['Confira a versão atual.']];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//h2[normalize-space(.)="Briefing ainda não salvo"]')->length);
        self::assertStringContainsString('O texto da tentativa foi mantido para consulta.',
            $xpath->evaluate('string(//*[@data-testid="unsaved-brief-notice"])'));
        self::assertSame(1, $xpath->query('//dd[normalize-space(.)="Tentativa preservada após perda de edição"]')->length);
        self::assertSame($data['brief']['headline'],
            $xpath->evaluate('string(//*[@data-testid="saved-current-brief"]//dd[@data-field="headline"])'));
        self::assertSame(0, $xpath->query('//form[@method="post"]|//input[@name="expected_revision"]')->length);
        self::assertStringNotContainsString('Briefing da versão atual', $html);
        self::assertStringNotContainsString('Gerar nova versão privada', $html);
        self::assertStringNotContainsString('A comparação mantém o formulário de edição na versão atual', $html);
    }

    public function test_new_project_recovery_without_edit_access_preserves_text_without_inventing_a_product(): void
    {
        $data = $this->newProject();
        $data['hasUnsavedBrief'] = true;
        $data['canEdit'] = false;
        $data['form']['product'] = '';
        $data['form']['brand'] = '<strong>Criação preservada</strong>';
        $data['fieldErrors'] = ['product' => ['Escolha um formato.']];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(1, $xpath->query('//*[@data-testid="unsaved-brief-notice"]')->length);
        self::assertSame(1, $xpath->query('//dd[normalize-space(.)="Formato não selecionado"]')->length);
        self::assertSame(1, $xpath->query('//dd[normalize-space(.)="<strong>Criação preservada</strong>"]')->length);
        self::assertSame(0, $xpath->query('//form[@method="post"]|//*[@data-testid="saved-current-brief"]|//dd/strong')->length);
        self::assertStringNotContainsString('Criar demonstração', $html);
    }

    public function test_ordinary_editor_and_reader_pages_do_not_claim_an_unsaved_brief(): void
    {
        foreach ([true, false] as $canEdit) {
            $data = $this->page();
            $data['canEdit'] = $canEdit;
            $data['navigation'] = PreviewNavigation::fromQuery(['before_revision' => '5'], true);
            // Existing view callers may omit the new response-only flag.
            unset($data['hasUnsavedBrief']);
            $html = view('preview.workspace', $data)->render();
            $xpath = $this->xpath($html);

            self::assertSame(0, $xpath->query('//*[@data-testid="unsaved-brief-notice" or @data-testid="saved-current-brief"]')->length);
            self::assertSame(0, $xpath->query('//form[@data-testid="briefing-form"]/@aria-describedby')->length);
            if ($canEdit) {
                self::assertStringContainsString('A comparação mantém o formulário de edição na versão atual 7.', $html);
                self::assertStringContainsString('O briefing de edição continua na versão atual.', $html);
                self::assertStringContainsString('Este briefing corresponde à versão atual 7.', $html);
                self::assertSame($data['brief']['headline'], $xpath->evaluate('string(//input[@name="headline"]/@value)'));
            } else {
                self::assertStringContainsString('O briefing salvo da versão atual 7 continua disponível para consulta.', $html);
                self::assertStringContainsString('O briefing salvo da versão atual continua disponível para consulta.', $html);
                self::assertStringNotContainsString('formulário de edição', $html);
                self::assertStringNotContainsString('briefing de edição', $html);
                self::assertStringContainsString('Briefing da versão atual', $html);
                self::assertSame(0, $xpath->query('//form[@method="post"]')->length);
            }
        }
    }

    public function test_review_feedback_is_not_mislabeled_as_an_unsaved_briefing(): void
    {
        $data = $this->page();
        $data['canEdit'] = false;
        $data['canReview'] = true;
        $data['fieldErrors'] = ['review' => ['Este parecer não foi registrado novamente.']];
        $data['reviewSubmission'] = [
            'revision' => 7, 'sha256' => $data['currentSha256'], 'decision' => 'changes_requested',
            'note' => 'Orientação editorial preservada', 'canRetry' => false,
        ];
        $xpath = $this->xpath(view('preview.workspace', $data)->render());

        self::assertSame(1, $xpath->query('//*[@data-testid="preserved-review"]')->length);
        self::assertSame(0, $xpath->query('//*[@data-testid="unsaved-brief-notice" or @data-testid="saved-current-brief"]')->length);
        self::assertSame(1, $xpath->query('//h2[normalize-space(.)="Briefing da versão atual"]')->length);
        self::assertSame('Orientação editorial preservada',
            $xpath->evaluate('string(//*[@data-testid="preserved-review"]//*[@data-review-note])'));
    }

    private function page(): array
    {
        $brief = ['productCode' => 'onepage', 'brand' => 'Marca salva', 'headline' => 'Chamada salva',
            'description' => 'Descrição salva', 'callToAction' => 'Botão salvo', 'benefits' => ['Benefício salvo']];
        return [
            'projects' => [], 'project' => '11111111-1111-4111-8111-111111111111', 'brief' => $brief,
            'versions' => [], 'reviews' => [], 'fieldErrors' => [],
            'currentReviews' => [], 'currentReviewsTruncated' => false, 'reviewSubmission' => null,
            'navigation' => PreviewNavigation::fromQuery([], true),
            'currentRevision' => 7, 'currentSha256' => str_repeat('a', 64), 'queueNext' => null, 'comparison' => null,
            'historyOlder' => null, 'historyNewer' => null, 'reviewsTruncated' => false,
            'form' => ['product' => 'onepage', 'brand' => $brief['brand'], 'headline' => $brief['headline'],
                'description' => $brief['description'], 'call_to_action' => $brief['callToAction'],
                'benefits' => implode("\n", $brief['benefits'])],
            'canEdit' => true, 'canReview' => false, 'hasUnsavedBrief' => false,
        ];
    }

    private function newProject(): array
    {
        return array_replace($this->page(), [
            'project' => null, 'brief' => null, 'currentRevision' => null, 'currentSha256' => null,
            'navigation' => PreviewNavigation::fromQuery([]),
        ]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }
}
