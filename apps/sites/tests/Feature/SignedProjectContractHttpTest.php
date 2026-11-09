<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticSignedCommand;
use Tests\TestCase;

final class SyntheticHttpReplayFixture implements ReplayStore
{
    /** @var array<string,bool> */
    private array $seen = [];
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        $key=$issuer.':'.$nonce;
        if(isset($this->seen[$key]))return false;
        $this->seen[$key]=true;
        return true;
    }
}

final class SyntheticPortalProjectGateFixture implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        $allowed=$command->tenantRef==='tenant-demo-001'
            && $command->contractRef==='contract-demo-001'
            && $command->contractRevision===1;
        return new PolicyDecision($allowed,$allowed?[]:['synthetic_contract_not_authorized']);
    }
}

final class SignedProjectContractHttpTest extends TestCase
{
    private const FAKE_KEY='DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
    private const PATH='/_lab/sites/v1/create-project';

    protected function setUp():void
    {
        parent::setUp();
        if(getenv('SITES_SYNTHETIC_HTTP')!=='1') {
            $this->markTestSkipped('The synthetic CLI-only HTTP kernel lab is disabled by default.');
        }
    }

    private function payload(string $tenant='tenant-demo-001'): SiteProjectCommand
    {
        $fixture=json_decode(file_get_contents(base_path('../../fixtures/portal-sites/create-project.synthetic.json')),
            true,512,JSON_THROW_ON_ERROR);
        $fixture['tenantRef']=$tenant;
        return SiteProjectCommand::fromArray($fixture);
    }

    private function signed(SiteProjectCommand $cmd,string $nonce):array
    {
        return SyntheticSignedCommand::sign($cmd,self::FAKE_KEY,$nonce,time(),time()+60);
    }

    private function enableOnlyFictionalGate():void
    {
        $this->app->instance(ReplayStore::class,new SyntheticHttpReplayFixture());
        $this->app->instance(PortalProjectAuthorityGateway::class,new SyntheticPortalProjectGateFixture());
    }

    public function test_default_without_replay_store_or_portal_authority_remains_denied():void
    {
        $this->postJson(self::PATH,$this->signed($this->payload(),'nonce-test-no-store-001'))
            ->assertStatus(403)->assertJsonMissing(['synthetic'=>true]);
    }

    public function test_command_passes_kernel_only_with_explicit_test_injections():void
    {
        $this->enableOnlyFictionalGate();
        $this->postJson(self::PATH,$this->signed($this->payload(),'nonce-test-accepted-001'))
            ->assertStatus(202)
            ->assertJsonPath('synthetic',true)
            ->assertJsonPath('status','contract_validated_not_executed')
            ->assertJsonPath('operationRef','op-demo-001');
    }

    public function test_second_request_with_same_signed_nonce_is_denied():void
    {
        $this->enableOnlyFictionalGate();
        $wire=$this->signed($this->payload(),'nonce-test-replay-001');
        $this->postJson(self::PATH,$wire)->assertStatus(202);
        $this->postJson(self::PATH,$wire)->assertStatus(403);
    }

    public function test_tampered_contract_is_denied_without_consuming_valid_nonce():void
    {
        $this->enableOnlyFictionalGate();
        $wire=$this->signed($this->payload(),'nonce-test-tamper-001');
        $altered=$wire;
        $altered['payload']['contractRevision']=2;
        $this->postJson(self::PATH,$altered)->assertStatus(403);
        $this->postJson(self::PATH,$wire)->assertStatus(202);
    }

    public function test_even_signed_other_tenant_is_denied_by_separate_gate():void
    {
        $this->enableOnlyFictionalGate();
        $this->postJson(self::PATH,$this->signed($this->payload('tenant-other-002'),'nonce-test-other-001'))
            ->assertStatus(403);
    }

    public function test_remote_address_is_blocked_before_contract_validation():void
    {
        $this->enableOnlyFictionalGate();
        $this->withServerVariables(['REMOTE_ADDR'=>'203.0.113.99'])
            ->postJson(self::PATH,$this->signed($this->payload(),'nonce-test-remote-001'))
            ->assertStatus(404);
    }
}
