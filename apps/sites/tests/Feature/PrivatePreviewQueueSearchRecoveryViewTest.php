<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewNavigation;
use App\Preview\PreviewQueueSearchInput;
use Imagine\Sites\Production\PreviewQueueQuery;
use Tests\TestCase;

final class PrivatePreviewQueueSearchRecoveryViewTest extends TestCase
{
    public function test_error_keeps_the_complete_escaped_search_and_filters_without_showing_any_results(): void
    {
        $data = $this->page();
        $search = " \u{00A0}".'"><script data-search-probe="literal">Texto</script>'.str_repeat('😀', 101)."\u{3000} ";
        $data['queueSearchInput'] = PreviewQueueSearchInput::recover($search);
        $cursor = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.000000Z', $this->project()['id']);
        $data['navigation'] = PreviewNavigation::fromQuery([
            'status' => 'changes_requested', 'format' => 'landing_page', 'cursor' => $cursor,
        ]);
        // Even if unrelated rows reach the template, an invalid search must
        // never appear to have produced a successful list or pagination.
        $data['projects'] = [$this->project()];
        $data['queueNext'] = $cursor;
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);
        $form = '//form[@data-testid="queue-filters"]';
        $input = $form.'//input[@name="q"]';

        self::assertSame('get', $xpath->evaluate('string('.$form.'/@method)'));
        self::assertSame('/_preview', $xpath->evaluate('string('.$form.'/@action)'));
        self::assertSame('Buscar projeto', $xpath->evaluate('string('.$form.'//label[@for="queue-search"])'));
        self::assertSame('search', $xpath->evaluate('string('.$input.'/@type)'));
        self::assertSame($search, $xpath->evaluate('string('.$input.'/@value)'));
        self::assertSame('true', $xpath->evaluate('string('.$input.'/@aria-invalid)'));
        self::assertSame('queue-search-help queue-search-error', $xpath->evaluate('string('.$input.'/@aria-describedby)'));
        self::assertSame('alert', $xpath->evaluate('string(//*[@data-testid="queue-search-error"]/@role)'));
        self::assertSame('queue-search-error', $xpath->evaluate('string(//*[@data-testid="queue-search-error"]/@id)'));
        self::assertSame('Use até 100 caracteres na busca. Encurte o texto e tente novamente.',
            $xpath->evaluate('string(//*[@data-testid="queue-search-error"])'));
        self::assertSame('changes_requested', $xpath->evaluate('string('.$form.'//select[@name="status"]/option[@selected]/@value)'));
        self::assertSame('landing_page', $xpath->evaluate('string('.$form.'//select[@name="format"]/option[@selected]/@value)'));
        self::assertSame(0, $xpath->query($input.'/@maxlength|'.$form.'//input[@type="hidden"]')->length);
        self::assertSame(0, $xpath->query('//*[@data-project]|//*[@data-testid="queue-empty"]|//nav[@aria-label="Páginas da fila editorial"]')->length);
        self::assertStringNotContainsString('PROJETO QUE NÃO É RESULTADO DA BUSCA', $html);
        self::assertStringNotContainsString('Nenhum projeto encontrado', $html);
        self::assertStringContainsString('&lt;script data-search-probe=&quot;literal&quot;&gt;', $html);
        self::assertSame(0, $xpath->query('//script|//*[@data-search-probe]')->length);

        self::assertSame('/_preview?status=changes_requested&format=landing_page',
            $xpath->evaluate('string(//a[normalize-space(.)="Limpar busca"]/@href)'));
        self::assertSame($data['navigation']->createUrl(), $xpath->evaluate('string(//form[@method="post"]/@action)'));
        foreach ($xpath->query('//a/@href|//form/@action') as $attribute) {
            parse_str(parse_url($attribute->value, PHP_URL_QUERY) ?? '', $parameters);
            self::assertArrayNotHasKey('q', $parameters);
            self::assertStringNotContainsString($search, $attribute->value);
            self::assertStringNotContainsString(rawurlencode($search), $attribute->value);
        }
        self::assertSame(0, $xpath->query('//form[@method="post"]//*[@name="q"]')->length);
        self::assertSame(1, $xpath->query('//*[@name="q"]')->length);
    }

    public function test_upper_bound_unicode_error_stays_editable_for_a_reader_without_a_false_empty_state(): void
    {
        $data = $this->page();
        $data['canEdit'] = false;
        $data['queueSearchInput'] = PreviewQueueSearchInput::recover(str_repeat('😀', 200));
        $html = view('preview.workspace', $data)->render();
        $xpath = $this->xpath($html);

        self::assertSame(str_repeat('😀', 200), $xpath->evaluate('string(//input[@name="q"]/@value)'));
        self::assertSame(0, $xpath->query('//input[@name="q"]/@maxlength|//input[@name="q"]/@disabled')->length);
        self::assertSame(1, $xpath->query('//form[@data-testid="queue-filters"]//button[@type="submit"]')->length);
        self::assertSame('Aplicar filtros', $xpath->evaluate('string(//form[@data-testid="queue-filters"]//button)'));
        self::assertSame('/_preview', $xpath->evaluate('string(//a[normalize-space(.)="Limpar busca"]/@href)'));
        self::assertSame(1, $xpath->query('//*[@data-testid="queue-search-error"]')->length);
        self::assertSame(0, $xpath->query('//form[@method="post"]|//*[@data-testid="queue-empty"]|//*[@data-project]|//nav[@aria-label="Páginas da fila editorial"]')->length);
        self::assertStringNotContainsString('Nenhum projeto encontrado', $html);
    }

    public function test_valid_search_renders_the_normal_queue_and_pager_without_a_recovery_error(): void
    {
        $data = $this->page();
        $data['queueSearchInput'] = null;
        $data['navigation'] = PreviewNavigation::fromQuery(['status' => 'changes_requested', 'format' => 'landing_page', 'q' => 'Busca corrigida']);
        $data['projects'] = [$this->project()];
        $data['queueNext'] = PreviewQueueQuery::cursorFor('2026-11-17T12:00:00.000000Z', $this->project()['id']);
        $xpath = $this->xpath(view('preview.workspace', $data)->render());

        self::assertSame('Busca corrigida', $xpath->evaluate('string(//input[@name="q"]/@value)'));
        self::assertSame('false', $xpath->evaluate('string(//input[@name="q"]/@aria-invalid)'));
        self::assertSame('queue-search-help', $xpath->evaluate('string(//input[@name="q"]/@aria-describedby)'));
        self::assertSame(0, $xpath->query('//*[@data-testid="queue-search-error"]|//input[@name="q"]/@maxlength')->length);
        self::assertSame(1, $xpath->query('//*[@data-project="'.$this->project()['id'].'"]')->length);
        self::assertSame(1, $xpath->query('//nav[@aria-label="Páginas da fila editorial"]//a')->length);
        self::assertSame($data['navigation']->queueUrlFor($data['queueNext']),
            $xpath->evaluate('string(//a[normalize-space(.)="Próximos projetos →"]/@href)'));
        self::assertSame(0, $xpath->query('//form[@data-testid="queue-filters"]//input[@name="cursor"]')->length);
    }

    private function page(): array
    {
        return [
            'projects' => [], 'project' => null, 'brief' => null,
            'versions' => [], 'reviews' => [], 'fieldErrors' => [],
            'currentReviews' => [], 'currentReviewsTruncated' => false, 'reviewSubmission' => null,
            'navigation' => PreviewNavigation::fromQuery([]),
            'currentRevision' => null, 'currentSha256' => null, 'queueNext' => null, 'comparison' => null,
            'historyOlder' => null, 'historyNewer' => null, 'reviewsTruncated' => false,
            'form' => ['product' => 'onepage', 'brand' => '', 'headline' => '', 'description' => '',
                'call_to_action' => '', 'benefits' => ''],
            'canEdit' => true, 'canReview' => false,
        ];
    }

    private function project(): array
    {
        return [
            'id' => '11111111-1111-4111-8111-111111111111', 'product' => 'landing_page',
            'brand' => 'PROJETO QUE NÃO É RESULTADO DA BUSCA', 'headline' => 'Chamada sintética',
            'latest_revision' => 2, 'latest_created_at' => '2026-11-17 12:00:00+00',
            'editorial_status' => 'changes_requested',
        ];
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($document);
    }
}
