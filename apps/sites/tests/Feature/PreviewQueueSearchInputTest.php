<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Preview\PreviewNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

final class PreviewQueueSearchInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_test/private-preview-search-input', static function (Request $request) {
            try {
                $navigation = PreviewNavigation::fromQuery($request->query());
                return response()->json([
                    'raw' => $request->query('q'), 'search' => $navigation->queue->search,
                    'url' => $navigation->queueUrl(),
                ]);
            } catch (InvalidArgumentException) {
                return response('Invalid search', 422);
            }
        });
    }

    public function test_get_form_empty_search_survives_the_real_global_middleware(): void
    {
        $this->get('/_test/private-preview-search-input?q=&status=all&format=all')
            ->assertOk()->assertExactJson(['raw' => null, 'search' => '', 'url' => '/_preview']);
        $this->get('/_test/private-preview-search-input?status=all&format=all')
            ->assertOk()->assertExactJson(['raw' => null, 'search' => '', 'url' => '/_preview']);
    }

    public function test_middleware_preserves_raw_search_for_domain_validation_and_allows_unicode_boundary(): void
    {
        $raw = " \u{00A0}Marca  Á 😀\u{2003} ";
        $this->get($this->url($raw))->assertOk()->assertJson([
            'raw' => $raw, 'search' => 'Marca  Á 😀',
        ]);
        $raw = str_repeat('😀', 100);
        $this->get($this->url($raw))->assertOk()->assertJson(['raw' => $raw, 'search' => $raw]);
    }

    public function test_middleware_cannot_trim_malformed_search_into_an_accepted_term(): void
    {
        foreach (["\0Marca\0", "\tMarca", "Marca\r\n", "Marca\u{0085}", "Marca\u{2029}", str_repeat('a', 101), str_repeat('😀', 101)] as $query) {
            $this->get($this->url($query))->assertStatus(422);
        }
        $this->get('/_test/private-preview-search-input?q%5B%5D=Marca')->assertStatus(422);
        $this->get('/_test/private-preview-search-input?q=&status=')->assertStatus(422);
    }

    private function url(string $query): string
    {
        return '/_test/private-preview-search-input?'.http_build_query(['q' => $query], '', '&', PHP_QUERY_RFC3986);
    }
}
