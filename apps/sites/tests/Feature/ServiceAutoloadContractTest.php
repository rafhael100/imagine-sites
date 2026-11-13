<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Imagine\Sites\Application\DenyingPortalReviewSnapshotSource;
use Imagine\Sites\Integration\DenyingLeadBindingAuthoritySource;
use ReflectionClass;
use Tests\TestCase;

final class ServiceAutoloadContractTest extends TestCase
{
    public function test_internal_portal_and_persistence_services_are_composer_autoloadable(): void
    {
        // Classes used by the synthetic workflow must be loadable from
        // Laravel's vendor/autoload.php; standalone fixture loaders are not
        // a substitute for the application's PSR-4 map.
        $services = [
            'Imagine\\Sites\\Application\\CurrentPortalReviewSnapshot'
                => '../../src/Application/CurrentPortalReviewSnapshot.php',
            'Imagine\\Sites\\Application\\DenyingPortalReviewSnapshotSource'
                => '../../src/Application/DenyingPortalReviewSnapshotSource.php',
            'Imagine\\Sites\\Application\\LabEvidenceBoundReviewProposal'
                => '../../src/Application/LabEvidenceBoundReviewProposal.php',
            'Imagine\\Sites\\Application\\SyntheticCrmLeadRetryPreflight'
                => '../../src/Application/SyntheticCrmLeadRetryPreflight.php',
            'Imagine\\Sites\\Persistence\\LabInternalReviewDecisionStore'
                => '../../src/Persistence/LabInternalReviewDecisionStore.php',
            'Imagine\\Sites\\Persistence\\LabPersistedReviewEvidence'
                => '../../src/Persistence/LabPersistedReviewEvidence.php',
            'Imagine\\Sites\\Persistence\\LabReviewLedgerCheckpoint'
                => '../../src/Persistence/LabReviewLedgerCheckpoint.php',
        ];
        foreach ($services as $class => $relativeFile) {
            $this->assertTrue(class_exists($class), "Missing Composer autoload for $class");
            $this->assertSame(
                realpath(base_path($relativeFile)),
                (new ReflectionClass($class))->getFileName(),
                "Wrong PSR-4 file for $class"
            );
        }

        $config = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        $map = $config['autoload']['psr-4'] ?? [];
        $this->assertSame(
            '../../src/Application/',
            $map['Imagine\\Sites\\Application\\'] ?? null
        );
        $this->assertSame(
            '../../src/Persistence/',
            $map['Imagine\\Sites\\Persistence\\'] ?? null
        );
    }

    public function test_default_denial_services_resolve_without_enabling_customer_routes(): void
    {
        $portal = app()->make(DenyingPortalReviewSnapshotSource::class);
        $leads = app()->make(DenyingLeadBindingAuthoritySource::class);
        $this->assertNull($portal->findCurrentFor('tenant-f85', 'site-f85'));
        $this->assertNull($leads->currentFor('tenant-f85', 'site-f85'));

        $this->get('/up')->assertOk();
        foreach (['/api/sites', '/api/leads', '/api/publish', '/api/integrations/portal'] as $route) {
            $this->get($route)->assertNotFound();
        }

        $this->assertFalse(
            collect(Route::getRoutes())->contains(
                fn ($route) => str_starts_with($route->uri(), 'api/sites')
            )
        );
    }
}
