<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use Imagine\Sites\Production\SyntheticPrivateDraftWriter;
use Tests\TestCase;

/** F1-87: no visible partially written packages on the local test filesystem. */
final class SyntheticPrivateDraftWriterTest extends TestCase
{
    private function directory():string
    {
        return storage_path('app/private/sites-f1-72-onepage-classic-basic');
    }

    private function privateFiles():array
    {
        $dir=$this->directory();
        return [
            'index.html'=>(string)file_get_contents($dir.'/index.html'),
            'internal-review-dossier.json'=>(string)file_get_contents($dir.'/internal-review-dossier.json'),
            'internal-review-draft.json'=>(string)file_get_contents($dir.'/internal-review-draft.json'),
            'release-manifest.json'=>(string)file_get_contents($dir.'/release-manifest.json'),
        ];
    }

    private function removeDirectory():void
    {
        $dir=$this->directory();
        if (!is_dir($dir))return;
        foreach(scandir($dir)?:[] as $name) {
            if ($name !== '.' && $name !== '..') @unlink($dir.'/'.$name);
        }
        @rmdir($dir);
    }

    private function assertNoStagingLeak():void
    {
        $entries=scandir(storage_path('app/private'))?:[];
        foreach($entries as $item) {
            self::assertFalse(str_starts_with($item,'.sites-f1-87-stage-'),
                'Staging directory must be absent after commit or failure');
        }
    }

    protected function tearDown():void
    {
        $this->removeDirectory();
        putenv('SITES_SYNTHETIC_DRAFT');
        putenv('SITES_SYNTHETIC_VERIFY');
        parent::tearDown();
    }

    public function test_writes_the_four_files_with_verified_readback_and_no_stage_leaks():void
    {
        putenv('SITES_SYNTHETIC_DRAFT=1');
        self::assertSame(0,Artisan::call('sites:assemble-draft-fixture'));
        $dir=$this->directory();
        $before=SyntheticPrivateDraftVerifier::verifyDirectory($dir);
        $files=$this->privateFiles();
        $this->assertNoStagingLeak();
        $this->removeDirectory();
        self::assertDirectoryDoesNotExist($dir);

        $after=SyntheticPrivateDraftWriter::create($dir,$files);
        self::assertSame($before,$after);
        self::assertSame($after,SyntheticPrivateDraftVerifier::verifyDirectory($dir));
        self::assertFalse($after['publishable']);
        self::assertFalse($after['officialClientAcceptance']);
        self::assertSame('pending',$after['reviewerDecisionStatus']);
        $this->assertNoStagingLeak();

        $originalHashes=array_map(static fn(string $bytes)=>hash('sha256',$bytes),$files);
        try {
            SyntheticPrivateDraftWriter::create($dir,$files);
            self::fail('Existing target must not be overwritten');
        } catch (DomainException $e) {
            self::assertSame('private_draft_writer_requires_fresh_fixed_target',
                $e->getMessage());
        }
        foreach($originalHashes as $name=>$sha) {
            self::assertSame($sha,hash_file('sha256',$dir.'/'.$name));
        }
        $this->assertNoStagingLeak();
    }

    public function test_invalid_bytes_are_denied_without_creating_a_partial_directory():void
    {
        putenv('SITES_SYNTHETIC_DRAFT=1');
        self::assertSame(0,Artisan::call('sites:assemble-draft-fixture'));
        $files=$this->privateFiles();
        $dir=$this->directory();
        $this->removeDirectory();
        $files['index.html'].="\n";
        try {
            SyntheticPrivateDraftWriter::create($dir,$files);
            self::fail('Modified HTML must be rejected before writing');
        } catch (DomainException) {
            self::assertDirectoryDoesNotExist($dir);
        }
        $this->assertNoStagingLeak();
    }

    public function test_arbitrary_destination_is_not_a_supported_export_target():void
    {
        putenv('SITES_SYNTHETIC_DRAFT=1');
        self::assertSame(0,Artisan::call('sites:assemble-draft-fixture'));
        $files=$this->privateFiles();
        $other=storage_path('app/private/production-site');
        try {
            SyntheticPrivateDraftWriter::create($other,$files);
            self::fail('Unrecognized destination must be rejected');
        } catch (DomainException) {
            self::assertDirectoryDoesNotExist($other);
        }
        $this->assertNoStagingLeak();
    }
}
