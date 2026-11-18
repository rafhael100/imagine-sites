<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewNavigation;
use Imagine\Sites\Production\PreviewQueueQuery;
use Tests\TestCase;

final class PrivatePreviewVersionReviewsViewTest extends TestCase
{
    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    public function test_each_card_and_the_current_guidance_open_the_selected_version_without_a_cursor(): void
    {
        $data = $this->page();
        $data['navigation'] = PreviewNavigation::fromQuery($this->context(), true);
        unset($data['versionReviews']);
        $xpath = $this->xpath(view('preview.workspace', $data)->render());

        self::assertSame(0, $xpath->query('//*[@data-testid="version-reviews-panel"]')->length);
        self::assertSame(1, $xpath->query('//h2[@id="version-history"]')->length);
        self::assertSame(3, $xpath->query('//a[@data-testid="version-reviews-link"]')->length);
        foreach ([40, 39, 61] as $revision) {
            $link = '//a[@data-testid="version-reviews-link" and @data-review-target="'.$revision.'"]';
            self::assertSame('Consultar pareceres da versão '.$revision, $xpath->evaluate('string('.$link.')'));
            $this->assertQuery($xpath->evaluate('string('.$link.'/@href)'),
                $this->context() + ['review_revision' => (string) $revision], 'version-reviews');
        }
        self::assertSame(0, $xpath->query('//*[@data-version="61"]')->length);
        self::assertSame(1, $xpath->query('//*[@data-testid="current-editorial-guidance"]//a[@data-review-target="61"]')->length,
            'Current feedback remains reachable when the head is outside the history window');
    }

    public function test_historical_reviews_keep_exact_escaped_notes_and_do_not_replace_the_current_brief(): void
    {
        $data = $this->page();
        $note = "\n  <script data-note-probe=literal>Orientação & revisão 😀</script>\n  ";
        $second = "Outra orientação\ncom duas linhas";
        $data['versionReviews']['reviews'] = [
            $this->review($note), $this->review($second, 'approved_internal'), $this->review(null),
        ];
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $panel = '//*[@data-testid="version-reviews-panel"]';

        self::assertSame('version-reviews', $xpath->evaluate('string('.$panel.'/@id)'));
        self::assertSame('version-reviews-title', $xpath->evaluate('string('.$panel.'/@aria-labelledby)'));
        self::assertSame('Pareceres da versão 3', $xpath->evaluate('string('.$panel.'/h2)'));
        self::assertSame('3', $xpath->evaluate('string('.$panel.'/@data-review-revision)'));
        self::assertSame(str_repeat('b', 64), $xpath->evaluate('string('.$panel.'/@data-review-digest)'));
        self::assertSame('SHA-256: '.str_repeat('b', 64), $xpath->evaluate('string('.$panel.'/code)'));
        self::assertSame(3, $xpath->query($panel.'//*[@data-testid="version-review-item"]')->length);
        self::assertSame([$note, $second], array_map(static fn (\DOMNode $node): string => $node->textContent,
            iterator_to_array($xpath->query($panel.'//*[@data-review-note]'))));
        self::assertSame('Este parecer foi registrado sem observação.',
            $xpath->evaluate('string('.$panel.'//*[@data-testid="version-review-no-note"])'));
        self::assertSame(2, $xpath->query($panel.'//strong[normalize-space(.)="Ajustes solicitados"]')->length);
        self::assertSame(1, $xpath->query($panel.'//strong[normalize-space(.)="Aprovada internamente"]')->length);
        self::assertStringContainsString('18/11/2026 09:00 · Brasília', $xpath->evaluate('string('.$panel.')'));
        self::assertStringContainsString('Mais recentes primeiro. Até 20 pareceres por página.',
            $xpath->evaluate('string('.$panel.')'));
        self::assertStringContainsString('A versão atual do projeto é 61.', $xpath->evaluate('string('.$panel.')'));
        self::assertSame(0, $xpath->query($panel.'//form|'.$panel.'//input|'.$panel.'//button|//script|//*[@data-note-probe]')->length);
        self::assertSame('61', $xpath->evaluate('string(//form[@data-testid="briefing-form"]//input[@name="expected_revision"]/@value)'));
        self::assertSame($data['brief']['headline'], $xpath->evaluate('string(//input[@name="headline"]/@value)'));
        self::assertSame('61', $xpath->evaluate('string(//*[@data-testid="current-editorial-guidance"]/@data-review-revision)'));
        self::assertStringContainsString('&lt;script data-note-probe=literal&gt;', $html);
    }

    public function test_review_paging_and_read_navigation_keep_context_without_adding_selectors_to_posts(): void
    {
        $cursor = str_repeat('a', 64);
        $next = str_repeat('f', 64);
        $data = $this->page($cursor);
        $data['versionReviews']['reviews'] = [$this->review('Orientação desta página')];
        $data['versionReviews']['next_cursor'] = $next;
        $data['comparison'] = [
            'from' => $this->version(1, str_repeat('d', 64)) + ['brief' => $data['brief']],
            'to' => $this->version(60, str_repeat('e', 64)) + ['brief' => $data['brief']],
            'changed_fields' => [],
        ];
        $xpath = $this->xpath(view('preview.workspace', $data)->render());
        $pager = '//*[@data-testid="version-reviews-pager"]';

        self::assertSame('Páginas dos pareceres da versão 3', $xpath->evaluate('string('.$pager.'/@aria-label)'));
        $this->assertQuery($xpath->evaluate('string('.$pager.'/a[normalize-space(.)="Pareceres anteriores →"]/@href)'),
            $this->context() + ['review_revision' => '3', 'review_cursor' => $next], 'version-reviews');
        $this->assertQuery($xpath->evaluate('string('.$pager.'/a[normalize-space(.)="Voltar aos pareceres mais recentes"]/@href)'),
            $this->context() + ['review_revision' => '3'], 'version-reviews');
        $this->assertQuery($xpath->evaluate('string('.$pager.'/a[normalize-space(.)="Fechar consulta de pareceres"]/@href)'),
            $this->context(), 'version-history');
        $this->assertQuery($xpath->evaluate('string(//*[@data-version="40"]//a[@data-testid="version-reviews-link"]/@href)'),
            $this->context() + ['review_revision' => '40'], 'version-reviews');

        foreach ([40, 39] as $revision) {
            $preview = '//a[normalize-space(.)="Visualizar prévia privada da versão '.$revision.' ↗"]';
            self::assertSame(1, $xpath->query($preview)->length);
            self::assertSame('/_preview/projects/'.self::PROJECT.'/versions/'.$revision.'/html',
                $xpath->evaluate('string('.$preview.'/@href)'));
            self::assertSame('_blank', $xpath->evaluate('string('.$preview.'/@target)'));
            self::assertSame('noopener', $xpath->evaluate('string('.$preview.'/@rel)'));
            $comparisonLink = '//a[normalize-space(.)="Comparar versão '.$revision.' com a versão '.($revision - 1).'"]';
            self::assertSame(1, $xpath->query($comparisonLink)->length);
            $this->assertQuery($xpath->evaluate('string('.$comparisonLink.'/@href)'),
                array_replace($this->context(), ['compare_from' => (string) ($revision - 1), 'compare_to' => (string) $revision])
                    + ['review_revision' => '3', 'review_cursor' => $cursor], null);
        }

        $comparisonForm = '//form[@data-testid="comparison-form"]';
        self::assertSame('3', $xpath->evaluate('string('.$comparisonForm.'//input[@type="hidden" and @name="review_revision"]/@value)'));
        self::assertSame($cursor, $xpath->evaluate('string('.$comparisonForm.'//input[@type="hidden" and @name="review_cursor"]/@value)'));
        $withoutComparison = $this->context();
        unset($withoutComparison['compare_from'], $withoutComparison['compare_to']);
        $this->assertQuery($xpath->evaluate('string(//a[normalize-space(.)="Fechar comparação"]/@href)'),
            $withoutComparison + ['review_revision' => '3', 'review_cursor' => $cursor], null);
        $history = $this->context();
        $history['before_revision'] = '39';
        $this->assertQuery($xpath->evaluate('string(//a[normalize-space(.)="Versões anteriores →"]/@href)'),
            $history + ['review_revision' => '3', 'review_cursor' => $cursor], null);

        $posts = $xpath->query('//form[@method="post"]');
        self::assertSame(3, $posts->length);
        foreach ($posts as $form) {
            parse_str((string) parse_url($form->getAttribute('action'), PHP_URL_QUERY), $query);
            self::assertSame([], array_intersect_key($query, array_flip([
                'review_revision', 'review_cursor', 'compare_from', 'compare_to',
            ])));
            self::assertSame(0, $xpath->query('.//*[@name="review_revision" or @name="review_cursor" or @name="compare_from" or @name="compare_to"]', $form)->length);
            self::assertSame($this->context()['q'], $query['q']);
        }
    }

    public function test_an_empty_first_page_identifies_a_version_without_reviews_and_offers_only_close(): void
    {
        $data = $this->page();
        $xpath = $this->xpath(view('preview.workspace', $data)->render());
        $panel = '//*[@data-testid="version-reviews-panel"]';

        self::assertSame('Esta versão ainda não recebeu parecer editorial.',
            trim($xpath->evaluate('string('.$panel.'//*[@data-testid="version-reviews-empty"])')));
        self::assertSame(0, $xpath->query($panel.'//*[@data-testid="version-review-item"]')->length);
        self::assertSame(1, $xpath->query($panel.'//*[@data-testid="version-reviews-pager"]/a')->length);
        self::assertSame('Fechar consulta de pareceres', $xpath->evaluate('string('.$panel.'//nav/a)'));
        self::assertSame('3', $xpath->evaluate('string('.$panel.'/@data-review-revision)'));
    }

    public function test_an_empty_later_page_offers_a_restart_without_claiming_that_the_version_has_no_reviews(): void
    {
        $data = $this->page(str_repeat('a', 64));
        $xpath = $this->xpath(view('preview.workspace', $data)->render());
        $panel = '//*[@data-testid="version-reviews-panel"]';

        self::assertSame('Nenhum parecer nesta página. Volte aos pareceres mais recentes.',
            $xpath->evaluate('string('.$panel.'//*[@data-testid="version-reviews-empty"])'));
        self::assertStringNotContainsString('Esta versão ainda não recebeu parecer editorial.', $xpath->evaluate('string('.$panel.')'));
        self::assertSame(2, $xpath->query($panel.'//nav/a')->length);
        self::assertSame(0, $xpath->query($panel.'//a[normalize-space(.)="Pareceres anteriores →"]')->length);
        $this->assertQuery($xpath->evaluate('string('.$panel.'//a[normalize-space(.)="Voltar aos pareceres mais recentes"]/@href)'),
            $this->context() + ['review_revision' => '3'], 'version-reviews');
    }

    public function test_read_only_access_keeps_the_review_panel_without_offering_any_mutation(): void
    {
        $data = $this->page();
        $data['canEdit'] = $data['canAppendVersion'] = $data['canReview'] = false;
        $data['versionReviews']['reviews'] = [$this->review('Orientação histórica legível')];
        $xpath = $this->xpath(view('preview.workspace', $data)->render());
        $panel = '//*[@data-testid="version-reviews-panel"]';

        self::assertSame('Orientação histórica legível', $xpath->evaluate('string('.$panel.'//*[@data-review-note])'));
        self::assertSame(0, $xpath->query('//form[@method="post"]')->length);
        self::assertSame($data['brief']['headline'],
            $xpath->evaluate('string(//*[@data-testid="briefing-readonly"]//dd[@data-field="headline"])'));
        self::assertStringContainsString('A versão atual do projeto é 61.', $xpath->evaluate('string('.$panel.')'));
        self::assertStringNotContainsString('formulário', $xpath->evaluate('string('.$panel.')'));
    }

    public function test_terminal_unsaved_brief_stays_separate_from_the_selected_historical_reviews(): void
    {
        $data = $this->page();
        $data['currentRevision'] = 2147483647;
        $data['canAppendVersion'] = false;
        $data['hasUnsavedBrief'] = true;
        $data['form']['headline'] = '<strong data-attempt-probe="literal">Tentativa ainda não salva</strong>';
        $data['versionReviews']['reviews'] = [$this->review('Orientação exclusiva da versão 3')];
        $xpath = $this->xpath(view('preview.workspace', $data)->render());
        $panel = '//*[@data-testid="version-reviews-panel"]';

        self::assertSame('3', $xpath->evaluate('string('.$panel.'/@data-review-revision)'));
        self::assertSame('Orientação exclusiva da versão 3', $xpath->evaluate('string('.$panel.'//*[@data-review-note])'));
        self::assertStringContainsString('A versão atual do projeto é 2147483647.', $xpath->evaluate('string('.$panel.')'));
        self::assertStringNotContainsString('formulário', $xpath->evaluate('string('.$panel.')'));
        self::assertSame($data['form']['headline'],
            $xpath->evaluate('string(//*[@data-testid="briefing-readonly"]//dd[@data-field="headline"])'));
        self::assertSame($data['brief']['headline'],
            $xpath->evaluate('string(//*[@data-testid="saved-current-brief"]//dd[@data-field="headline"])'));
        self::assertSame('2147483647', $xpath->evaluate('string(//*[@data-testid="saved-current-brief"]/@data-brief-revision)'));
        self::assertSame(0, $xpath->query('//form[@data-testid="briefing-form"]|//input[@name="expected_revision"]|//*[@data-attempt-probe]')->length);
        self::assertSame(1, $xpath->query('//*[@data-testid="revision-limit-notice"]')->length);
        self::assertSame(2, $xpath->query('//form[@class="review"]')->length,
            'Reading a historical review does not change existing editorial permission');
    }

    private function context(): array
    {
        return [
            'status' => 'changes_requested', 'format' => 'onepage', 'q' => 'Marca "seleção" 😀',
            'cursor' => PreviewQueueQuery::cursorFor('2026-11-18T12:00:00.000000Z', self::PROJECT),
            'before_revision' => '41', 'compare_from' => '1', 'compare_to' => '60',
        ];
    }

    private function page(?string $cursor = null): array
    {
        $query = $this->context() + ['review_revision' => '3'];
        if ($cursor !== null) $query['review_cursor'] = $cursor;
        $brief = ['productCode' => 'onepage', 'brand' => 'Marca atual', 'headline' => 'Chamada da versão atual',
            'description' => 'Descrição atual', 'callToAction' => 'Conheça', 'benefits' => ['Benefício atual']];
        return [
            'project' => self::PROJECT, 'projects' => [], 'brief' => $brief,
            'versions' => [$this->version(40, str_repeat('d', 64)), $this->version(39, str_repeat('e', 64))],
            'reviews' => [], 'currentReviews' => [], 'reviewsTruncated' => false, 'currentReviewsTruncated' => false,
            'currentRevision' => 61, 'currentSha256' => str_repeat('c', 64), 'comparison' => null,
            'historyOlder' => 39, 'historyNewer' => 40, 'queueNext' => null,
            'navigation' => PreviewNavigation::fromQuery($query, true),
            'versionReviews' => ['revision' => 3, 'sha256' => str_repeat('b', 64), 'reviews' => [], 'next_cursor' => null],
            'form' => ['product' => 'onepage', 'brand' => $brief['brand'], 'headline' => $brief['headline'],
                'description' => $brief['description'], 'call_to_action' => $brief['callToAction'],
                'benefits' => implode("\n", $brief['benefits'])],
            'canEdit' => true, 'canAppendVersion' => true, 'canReview' => true,
            'hasUnsavedBrief' => false, 'fieldErrors' => [], 'reviewSubmission' => null,
        ];
    }

    private function version(int $revision, string $digest): array
    {
        return ['revision' => $revision, 'sha256' => $digest,
            'created_at' => '2026-11-18 12:00:00+00', 'reviewable' => true];
    }

    private function review(?string $note, string $decision = 'changes_requested'): array
    {
        return ['revision' => 3, 'sha256' => str_repeat('b', 64), 'decision' => $decision,
            'note' => $note, 'created_at' => '2026-11-18 12:00:00+00'];
    }

    private function assertQuery(string $url, array $expected, ?string $fragment): void
    {
        self::assertSame('/_preview/projects/'.self::PROJECT, parse_url($url, PHP_URL_PATH));
        self::assertSame($fragment, parse_url($url, PHP_URL_FRAGMENT));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $actual);
        ksort($actual);
        ksort($expected);
        self::assertSame($expected, $actual);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }
}
