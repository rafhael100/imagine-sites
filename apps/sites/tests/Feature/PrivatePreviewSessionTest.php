<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PrivatePreviewSessionTest extends TestCase
{
    private array $cookieJar = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The normal unit-test environment skips CSRF. Exercise the actual web
        // stack here with real encrypted cookie sessions and no database route.
        $this->app->instance('env', 'staging');
        config()->set('session.driver', 'cookie');
        config()->set('session.cookie', 'sites-preview-test');
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'strict');
        config()->set('session.lottery', [0, 100]);

        $probe = static fn (Request $request) => response()->json([
            'search' => $request->query('q'),
            'has_previous' => $request->session()->has('_previous'),
            'token' => $request->session()->token(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
        ]);
        foreach (['/_preview', '/_preview/session-probe', '/_ordinary/session-probe', '/_preview-extra'] as $path) {
            Route::match(['GET', 'POST'], $path, $probe)->middleware('web');
        }
        Route::get('/_ordinary/locked-session', $probe)->middleware('web')->block(1, 1);
    }

    public function test_long_unicode_search_stays_out_of_session_and_fits_fastcgi_header_budget(): void
    {
        $term = str_repeat('😀', 100);
        $query = ['q' => $term, 'status' => 'all', 'format' => 'all'];
        $before = $this->requestWithCookies('GET', $this->url('/_ordinary/session-probe', $query));
        $before->assertOk()->assertJson(['search' => $term]);
        self::assertGreaterThan(4096, strlen((string) $before->headers),
            'The framework previous-URL cookie reproduces the oversized FastCGI headers.');
        self::assertSame($this->url('/_ordinary/session-probe', $query), $this->sessionPayload()['_previous']['url']);

        $preview = $this->requestWithCookies('GET', $this->url('/_preview', $query));
        $preview->assertOk()->assertJson(['search' => $term, 'has_previous' => false]);
        self::assertSame($before->json('token'), $preview->json('token'));
        self::assertLessThan(4096, strlen((string) $preview->headers));
        $payload = $this->sessionPayload();
        self::assertArrayNotHasKey('_previous', $payload);
        self::assertArrayNotHasKey('q', $payload);
        self::assertArrayNotHasKey('search', $payload);
        self::assertStringNotContainsString($term, serialize($payload));
        self::assertStringNotContainsString(rawurlencode($term), serialize($payload));
        self::assertSame($preview->json('token'), $payload['_token']);
        foreach ($preview->headers->getCookies() as $cookie) {
            self::assertTrue($cookie->isSecure());
            self::assertSame('strict', $cookie->getSameSite());
            if ($cookie->getName() !== 'XSRF-TOKEN') self::assertTrue($cookie->isHttpOnly());
            self::assertStringNotContainsString($preview->json('token'), (string) $cookie);
        }
    }

    public function test_preview_nested_routes_clear_old_previous_url_before_the_controller_and_preserve_csrf(): void
    {
        $ordinary = $this->requestWithCookies('GET', $this->url('/_ordinary/session-probe', ['q' => 'old-navigation']));
        $ordinary->assertOk();
        self::assertArrayHasKey('_previous', $this->sessionPayload());
        $token = $ordinary->json('token');

        $preview = $this->requestWithCookies('GET', $this->url('/_preview/session-probe', ['q' => 'new-navigation']));
        $preview->assertOk()->assertJson(['has_previous' => false, 'search' => 'new-navigation', 'token' => $token]);
        self::assertArrayNotHasKey('_previous', $this->sessionPayload());

        $this->requestWithCookies('POST', $this->url('/_preview/session-probe'), ['_token' => $token])
            ->assertOk()->assertJson(['has_previous' => false, 'token' => $token]);
        self::assertSame($token, $this->sessionPayload()['_token']);
        $this->requestWithCookies('POST', $this->url('/_preview/session-probe'))->assertStatus(419);
        $this->requestWithCookies('POST', $this->url('/_preview/session-probe'), ['_token' => 'wrong-synthetic-token'])
            ->assertStatus(419);
    }

    public function test_other_routes_keep_framework_previous_url_and_session_blocking_behavior(): void
    {
        $ordinaryUrl = $this->url('/_ordinary/session-probe', ['q' => 'ordinary']);
        $ordinary = $this->requestWithCookies('GET', $ordinaryUrl);
        $ordinary->assertOk();
        self::assertSame($ordinaryUrl, $this->sessionPayload()['_previous']['url']);

        $similarUrl = $this->url('/_preview-extra', ['q' => 'similar-prefix']);
        $similar = $this->requestWithCookies('GET', $similarUrl);
        $similar->assertOk()->assertJson(['has_previous' => true, 'token' => $ordinary->json('token')]);
        self::assertSame($similarUrl, $this->sessionPayload()['_previous']['url']);

        $lockedUrl = $this->url('/_ordinary/locked-session');
        $this->requestWithCookies('GET', $lockedUrl)->assertOk();
        self::assertSame($lockedUrl, $this->sessionPayload()['_previous']['url']);
    }

    private function requestWithCookies(string $method, string $url, array $parameters = []): TestResponse
    {
        // Simulate separate FPM requests: do not reuse in-memory session data or
        // queued response cookies. Only the actual encrypted cookies survive.
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->make('cookie')->flushQueuedCookies();
        $response = $this->call($method, $url, $parameters, $this->cookieJar);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() > 0 && $cookie->getExpiresTime() < time()) {
                unset($this->cookieJar[$cookie->getName()]);
            } else {
                $this->cookieJar[$cookie->getName()] = $cookie->getValue();
            }
        }
        return $response;
    }

    private function sessionPayload(): array
    {
        $name = config('session.cookie');
        $id = $this->decryptCookie($name);
        $stored = json_decode($this->decryptCookie($id), true, 12, JSON_THROW_ON_ERROR);
        $payload = json_decode($stored['data'], true, 12, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    private function decryptCookie(string $name): string
    {
        $encrypter = $this->app->make('encrypter');
        $value = CookieValuePrefix::validate($name,
            $encrypter->decrypt($this->cookieJar[$name], false), $encrypter->getAllKeys());
        self::assertIsString($value);
        return $value;
    }

    private function url(string $path, array $query = []): string
    {
        ksort($query);
        return 'https://preview.example.invalid'.$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
