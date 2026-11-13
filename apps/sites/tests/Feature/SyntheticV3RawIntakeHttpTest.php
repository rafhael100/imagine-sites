<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Imagine\Sites\Domain\PolicyDecision;
use Imagine\Sites\Integration\PortalProjectAuthorityGateway;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Security\ReplayStore;
use Imagine\Sites\Security\SyntheticCommercialEpochSignedCommand as V3;
use Imagine\Sites\Security\SyntheticSignedCommand as V1;
use Imagine\Sites\Security\SyntheticVersionedSignedCommand as V2;
use Tests\TestCase;

/**
 * F1-299: no HTTP network or trusted Portal integration; all data fictional.
 */
final class ReplayV3299 implements ReplayStore
{
    private array $seen=[];
    public int $invocations=0;

    public function rememberOnce(string $issuer,string $nonce,int $expiresAt): bool
    {
        ++$this->invocations;
        $key=$issuer."\0".$nonce;
        if (isset($this->seen[$key])) return false;
        $this->seen[$key]=true;
        return true;
    }
}

final class GateV3299 implements PortalProjectAuthorityGateway
{
    public function verify(SiteProjectCommand $command): PolicyDecision
    {
        $allow=$command->tenantRef==='tenant-demo-001' &&
            $command->contractRef==='contract-demo-001' &&
            $command->contractRevision===1;
        return new PolicyDecision($allow,$allow?[]:['synthetic_authority_denied']);
    }
}

final class SyntheticV3RawIntakeHttpTest extends TestCase
{
    private const PATH='/_lab/sites/v3/create-project';
    private const KEY='DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';
    private const JSON_FLAGS=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE;

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_HTTP=1');
        putenv('SITES_SYNTHETIC_V3_INTAKE=1');
        parent::setUp();
    }

    protected function tearDown():void
    {
        putenv('SITES_SYNTHETIC_V3_INTAKE');
        putenv('SITES_SYNTHETIC_HTTP');
        parent::tearDown();
    }

    private function command(string $tenant='tenant-demo-001'):SiteProjectCommand
    {
        $raw=(string)file_get_contents(base_path(
            '../../fixtures/portal-sites/create-project.synthetic.json'
        ));
        $payload=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        $payload['tenantRef']=$tenant;
        return SiteProjectCommand::fromArray($payload);
    }

    private function v3Wire(string $nonce,string $tenant='tenant-demo-001',
        int $keyEpoch=3,int $commercialEpoch=7):string
    {
        $now=time();
        return json_encode(V3::sign(
            $this->command($tenant),self::KEY,$nonce,$now-3,$now+60,
            $keyEpoch,$commercialEpoch
        ),self::JSON_FLAGS);
    }

    private function rawPost(
        string $wire,string $contentType='application/json',
        string $remote='127.0.0.1'
    ):TestResponse {
        return $this->call('POST',self::PATH,[],[],[],[
            'CONTENT_TYPE'=>$contentType,'REMOTE_ADDR'=>$remote,
        ],$wire);
    }

    private function allowFictionalOnly():ReplayV3299
    {
        $store=new ReplayV3299();
        $this->app->instance(ReplayStore::class,$store);
        $this->app->instance(PortalProjectAuthorityGateway::class,new GateV3299());
        return $store;
    }

    public function test_default_deny_without_identity_source_and_replay_store():void
    {
        $this->rawPost($this->v3Wire('v3-299-no-authority'))
            ->assertStatus(403)->assertJsonMissing(['synthetic'=>true]);
    }

    public function test_valid_canonical_v3_only_in_explicit_fictional_injection():void
    {
        $store=$this->allowFictionalOnly();
        $this->rawPost($this->v3Wire('v3-299-valid'))
            ->assertStatus(202)
            ->assertHeader('X-Robots-Tag','noindex, nofollow, noarchive')
            ->assertJsonPath('status','v3_contract_validated_not_executed')
            ->assertJsonPath('synthetic',true)
            ->assertJsonPath('publishable',false)
            ->assertJsonPath('officialClientAcceptance',false)
            ->assertJsonPath('operationRef','op-demo-001');
        self::assertSame(1,$store->invocations);
    }

    public function test_raw_ambiguous_json_and_invalid_mac_never_reserve_nonce():void
    {
        $store=$this->allowFictionalOnly();
        $wire=$this->v3Wire('v3-299-ambiguous');
        $variants=[
            "\n".$wire,
            str_replace('"issuer"','"\u0069ssuer"',$wire),
            str_replace('"mac":','"mac":"'.str_repeat('0',64).'","mac":',$wire),
            str_replace(',"audience"',', "audience"',$wire),
            $wire."\n",
        ];
        foreach ($variants as $bad) {
            $this->rawPost($bad)->assertStatus(403);
        }
        $tampered=json_decode($wire,true,16,JSON_THROW_ON_ERROR);
        $tampered['payload']['contractRevision']=2;
        $this->rawPost(json_encode($tampered,self::JSON_FLAGS))->assertStatus(403);
        self::assertSame(0,$store->invocations,
            'Malformed or unsigned raw requests cannot consume a nonce');
        $this->rawPost($wire)->assertStatus(202);
        self::assertSame(1,$store->invocations);
    }

    public function test_replay_and_old_v1_v2_envelopes_rejected():void
    {
        $store=$this->allowFictionalOnly();
        $wire=$this->v3Wire('v3-299-replay');
        $this->rawPost($wire)->assertStatus(202);
        $this->rawPost($wire)->assertStatus(403);
        self::assertSame(2,$store->invocations);

        $now=time();
        $cmd=$this->command();
        $legacy=[
            V1::sign($cmd,self::KEY,'v3-299-old-v1',$now-1,$now+60),
            V2::sign($cmd,self::KEY,'v3-299-old-v2',$now-1,$now+60,3),
        ];
        foreach ($legacy as $envelope) {
            $this->rawPost(json_encode($envelope,self::JSON_FLAGS))->assertStatus(403);
        }
        self::assertSame(2,$store->invocations);
    }

    public function test_tenant_epochs_content_type_and_lab_runtime_flags():void
    {
        $store=$this->allowFictionalOnly();
        $this->rawPost($this->v3Wire('v3-299-other','tenant-other'))
            ->assertStatus(403);
        $this->rawPost($this->v3Wire('v3-299-stale-key','tenant-demo-001',2))
            ->assertStatus(403);
        $this->rawPost($this->v3Wire('v3-299-stale-contract','tenant-demo-001',3,8))
            ->assertStatus(403);
        $this->rawPost($this->v3Wire('v3-299-no-json'),'text/plain')
            ->assertStatus(415);
        $this->rawPost($this->v3Wire('v3-299-remote'),'application/json','203.0.113.33')
            ->assertNotFound();
        $count=$store->invocations;
        putenv('SITES_SYNTHETIC_V3_INTAKE');
        try {
            $this->rawPost($this->v3Wire('v3-299-disabled'))->assertNotFound();
        } finally {
            putenv('SITES_SYNTHETIC_V3_INTAKE=1');
        }
        self::assertSame($count,$store->invocations);
    }
}
