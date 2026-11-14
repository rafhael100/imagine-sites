<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Editorial\EditorialRevisionWriter;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EditorialRevisionWriterTest extends TestCase
{
    private const SITE='00000000-0000-4000-8000-000000000055';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F385_EDITORIAL_LAB')!=='1' ||
            getenv('DB_DATABASE')!=='sites_f383_lab') {
            $this->markTestSkipped('F1-385 immutable PG18 fixture disabled.');
        }
    }

    private function writer(string $role,string $password): EditorialRevisionWriter
    {
        $settings=config('database.connections.pgsql');
        $settings['database']='sites_f383_lab';
        $settings['host']='127.0.0.1';
        $settings['port']='5432';
        $settings['username']=$role;
        $settings['password']=$password;
        $connection='f385_'.$role;
        config(['database.connections.'.$connection=>$settings]);
        DB::purge($connection);
        return new EditorialRevisionWriter(DB::connection($connection));
    }

    public function test_real_laravel_writer_requires_independent_review_and_never_publishes():void
    {
        $editor=$this->writer(
            'sites_f385_editor_a','f385-fictional-editor-a'
        );
        $reviewer=$this->writer(
            'sites_f385_reviewer_a','f385-fictional-reviewer-a'
        );
        $digest=hash('sha256','F1-385 fictional HTML preview for Laravel.');
        $initial=$editor->transition(self::SITE,0,'register',$digest);
        $this->assertSame([
            'revision'=>1,'state'=>'draft','digest'=>$digest,
            'clientAccepted'=>false,'publishable'=>false,
        ],$initial);

        try {
            $reviewer->transition(self::SITE,1,'submit',$digest);
            $this->fail('reviewer cannot submit an editor draft');
        } catch(DomainException $e) {
            $this->assertSame(
                'Sites editorial operation unavailable.',$e->getMessage()
            );
        }

        $submitted=$editor->transition(self::SITE,1,'submit',$digest);
        $this->assertSame(2,$submitted['revision']);
        $this->assertSame('internal_review',$submitted['state']);
        $this->assertFalse($submitted['publishable']);

        $approved=$reviewer->transition(
            self::SITE,2,'approve_internal',$digest
        );
        $this->assertSame(3,$approved['revision']);
        $this->assertSame(
            'awaiting_portal_acceptance',$approved['state']
        );
        $this->assertFalse($approved['clientAccepted']);
        $this->assertFalse($approved['publishable']);

        $this->expectException(DomainException::class);
        $editor->transition(self::SITE,3,'revise',$digest);
    }

    public function test_admin_login_is_not_an_editorial_principal():void
    {
        $writer=$this->writer(
            'postgres','f383-fictional-admin-only'
        );
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Sites editorial operation unavailable.');
        $writer->transition(self::SITE,0,'register',str_repeat('d',64));
    }

    protected function tearDown():void
    {
        foreach([
            'sites_f385_editor_a','sites_f385_reviewer_a','postgres',
        ] as $role) {
            $connection='f385_'.$role;
            if (config('database.connections.'.$connection)!==null) {
                DB::disconnect($connection);
            }
        }
        parent::tearDown();
    }
}
