<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    public function test_only_non_business_bootstrap_health_check_exists(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_operational_and_customer_routes_remain_inaccessible(): void
    {
        foreach (['/', '/login', '/admin', '/checkout', '/api/sites', '/api/leads',
            '/api/publish', '/api/integrations/portal'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
