<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\SyntheticLabRouteFence;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * F1-233: request-time protection even if a cached Laravel route remains
 * registered after the original synthetic CLI environment has disappeared.
 */
final class SyntheticLabCachedRouteFenceTest extends TestCase
{
    private const PATH='/_lab/sites/v1/create-project';

    protected function setUp(): void
    {
        // Register the lab routes during app bootstrap, THEN simulate a
        // configuration/environment change without reloading the router.
        putenv('SITES_SYNTHETIC_HTTP=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('SITES_SYNTHETIC_HTTP');
        parent::tearDown();
    }

    private function directFence(string $path): array
    {
        $called=0;
        $req=Request::create($path,'GET',[],[],[],[
            'REMOTE_ADDR'=>'127.0.0.1',
        ]);
        $response=(new SyntheticLabRouteFence())->handle(
            $req,
            static function(Request $r) use (&$called):Response {
                ++$called;
                return response('handler reached',218);
            }
        );
        return [$response->getStatusCode(),$called];
    }

    public function test_registered_route_is_blocked_after_global_flag_is_revoked():void
    {
        // The lab registration exists, and the per-handler no-authority
        // default denies the unsigned command even when the lab is enabled.
        $this->postJson(self::PATH,[])->assertStatus(403);
        putenv('SITES_SYNTHETIC_HTTP');
        // Route remains registered in this same Laravel application. Only
        // the new GLOBAL request-time gate makes this a hard 404.
        $this->postJson(self::PATH,[])->assertNotFound();
        self::assertSame([404,0],$this->directFence('/_lab/sites/v1/review'));
    }

    public function test_reused_route_is_denied_after_application_changes_to_production():void
    {
        $this->postJson(self::PATH,[])->assertStatus(403);
        $original=$this->app['env'];
        $this->app['env']='production';
        try {
            $this->postJson(self::PATH,[])->assertNotFound();
            self::assertSame([404,0],$this->directFence(
                '/_lab/sites/v1/seo-copy-review'
            ));
            $this->get('/up')->assertOk();
        } finally {
            $this->app['env']=$original;
        }
    }

    public function test_independent_remote_address_denial_for_lab_and_fake_proxy():void
    {
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.55'])
            ->postJson(self::PATH,[])->assertNotFound();

        // A forwarded loopback claim must not rescue a remote socket.
        $this->withServerVariables([
            'REMOTE_ADDR'=>'203.0.113.55',
            'HTTP_X_FORWARDED_FOR'=>'127.0.0.1',
        ])->postJson(self::PATH,[])->assertNotFound();
    }

    public function test_exact_namespace_and_descendants_are_guarded_without_routing():void
    {
        putenv('SITES_SYNTHETIC_HTTP');
        foreach([
            '/_lab/sites/v1','/_lab/sites/v1/',
            '/_lab/sites/v1/create-project',
            '/_lab/sites/v1/seo-copy-review',
            '/_lab/sites/v1/arbitrary-new-route'
        ] as $url) {
            self::assertSame([404,0],$this->directFence($url));
        }
    }

    public function test_unrelated_paths_and_enabled_cli_lab_keep_separate_controls():void
    {
        self::assertSame([218,1],$this->directFence('/up'));
        self::assertSame([218,1],$this->directFence('/api/sites'));
        self::assertSame([218,1],$this->directFence('/_lab/sites/v2/example'));
        self::assertSame([218,1],$this->directFence(self::PATH));
        // The request-time boundary does not grant project acceptance:
        // the route independently returns 403 by default.
        $this->postJson(self::PATH,[])->assertStatus(403);
        $this->get('/up')->assertOk();
    }
}
