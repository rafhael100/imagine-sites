<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class SyntheticReleaseBundleCommandTest extends TestCase
{
    private function path(string $product): string
    {
        return storage_path('app/private/sites-f1-24-'.$product);
    }

    protected function tearDown(): void
    {
        foreach (['onepage','landing_page'] as $product) {
            $dir=$this->path($product);
            if (is_link($dir)) {
                unlink($dir);
                continue;
            }
            if (is_dir($dir)) {
                foreach (['index.html','release-manifest.json'] as $filename) {
                    @unlink($dir.'/'.$filename);
                }
                @rmdir($dir);
            }
        }
        foreach (['onepage','landing_page'] as $product) {
            @unlink(storage_path('app/private/.sites-f1-24-'.$product.'.lock'));
        }
        putenv('SITES_SYNTHETIC_BUNDLE');
        putenv('SITES_SYNTHETIC_VERIFY');
        parent::tearDown();
    }

    public function test_denied_without_explicit_lab_flag(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE');
        self::assertSame(1,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        self::assertDirectoryDoesNotExist($this->path('onepage'));
    }

    public function test_private_bundle_contains_exact_rendered_bytes_and_never_publishable_metadata(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        $dir=$this->path('onepage');
        self::assertFileExists($dir.'/index.html');
        self::assertFileExists($dir.'/release-manifest.json');
        $html=(string)file_get_contents($dir.'/index.html');
        $meta=json_decode((string)file_get_contents($dir.'/release-manifest.json'),true,16,JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256',$html),$meta['artifactDigest']);
        self::assertSame(strlen($html),$meta['artifactSizeBytes']);
        self::assertFalse($meta['publishable']);
        self::assertFalse($meta['officialClientAcceptance']);
        self::assertStringContainsString('noindex, nofollow',$html);
        self::assertStringContainsString('PRIVATE SYNTHETIC TEST PACKAGE ONLY',Artisan::output());
        $release=new \Imagine\Sites\Domain\ReleaseManifest(
            'tenant-fixture','site-fixture','release-bundle-onepage',$meta['artifactDigest']
        );
        $files=[
            'index.html'=>$html,
            'release-manifest.json'=>(string)file_get_contents($dir.'/release-manifest.json'),
        ];
        $pinnedDigest=hash('sha256',$files['index.html']."\0".$files['release-manifest.json']);
        self::assertTrue(\Imagine\Sites\Production\SyntheticReleaseBundle::verifyExactBundle(
            $files,$release,$pinnedDigest
        ));
        $files['release-manifest.json'].="\n";
        self::assertFalse(\Imagine\Sites\Production\SyntheticReleaseBundle::verifyExactBundle(
            $files,$release,$pinnedDigest
        ));
    }

    public function test_staging_files_are_exclusive_0600_under_permissive_umask(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        $originalUmask=umask(0000);
        try {
            self::assertSame(0,Artisan::call('sites:package-fixture',
                ['product'=>'landing_page']));
        } finally {
            umask($originalUmask);
        }
        $directory=$this->path('landing_page');
        $html=(string)file_get_contents($directory.'/index.html');
        $manifest=(string)file_get_contents($directory.'/release-manifest.json');
        foreach (['index.html','release-manifest.json'] as $name) {
            $path=$directory.'/'.$name;
            clearstatcache(true,$path);
            self::assertSame(0600,fileperms($path) & 0777,
                'Final private file must inherit restrictive creation mode');
        }
        clearstatcache(true,storage_path('app/private/.sites-f1-24-landing_page.lock'));
        self::assertSame(
            0600,
            fileperms(storage_path('app/private/.sites-f1-24-landing_page.lock')) & 0777
        );
        self::assertStringContainsString('noindex, nofollow',$html);
        self::assertSame(hash('sha256',$html),
            json_decode($manifest,true,16,JSON_THROW_ON_ERROR)['artifactDigest']);
        $leftovers=glob(storage_path('app/private/.sites-f1-24-landing_page-*.tmp'));
        self::assertSame([],$leftovers===false?[]:$leftovers);
    }

    public function test_second_write_cannot_replace_previously_verified_bundle(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        $directory=$this->path('onepage');
        $before=[
            hash_file('sha256',$directory.'/index.html'),
            hash_file('sha256',$directory.'/release-manifest.json'),
        ];
        self::assertSame(1,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        self::assertSame($before,[
            hash_file('sha256',$directory.'/index.html'),
            hash_file('sha256',$directory.'/release-manifest.json'),
        ], 'Preexisting validated package must never be overwritten');
        $leftovers=glob(storage_path('app/private/.sites-f1-24-onepage-*.tmp'));
        self::assertSame([],$leftovers===false ? [] : $leftovers,
            'Temporary staging paths must not be left after successful promotion');
    }

    public function test_read_only_verifier_requires_both_flags_and_two_exact_pins(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'onepage']));
        $dir=$this->path('onepage');
        $original=[
            'index.html'=>(string)file_get_contents($dir.'/index.html'),
            'release-manifest.json'=>(string)file_get_contents($dir.'/release-manifest.json'),
        ];
        $artifactDigest=hash('sha256',$original['index.html']);
        $bundleDigest=hash('sha256',$original['index.html']."\0".
            $original['release-manifest.json']);
        $args=[
            'product'=>'onepage',
            'artifactDigest'=>$artifactDigest,
            'bundleDigest'=>$bundleDigest,
        ];

        putenv('SITES_SYNTHETIC_VERIFY');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
            'Readback is never enabled merely by permitting package creation');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$args),
            'Pinned original package must pass read-only verification');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['artifactDigest'=>str_repeat('0',64)])),
            'Wrong independently pinned HTML digest must fail');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['bundleDigest'=>str_repeat('0',64)])),
            'Wrong independently pinned full bundle digest must fail');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['product'=>'landing_page'])),
            'Another product cannot claim the same pinned package');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['product'=>'../onepage'])),
            'Path-like product arguments are never used as filenames');
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
            array_replace($args,['bundleDigest'=>strtoupper($bundleDigest)])),
            'Noncanonical pin representations fail closed');

        self::assertSame($original['index.html'],
            file_get_contents($dir.'/index.html'));
        self::assertSame($original['release-manifest.json'],
            file_get_contents($dir.'/release-manifest.json'),
            'Verification itself must never rewrite the bundle');
    }

    public function test_read_only_verifier_detects_mutated_or_extra_private_files(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'landing_page']));
        $dir=$this->path('landing_page');
        $html=(string)file_get_contents($dir.'/index.html');
        $manifest=(string)file_get_contents($dir.'/release-manifest.json');
        $args=[
            'product'=>'landing_page',
            'artifactDigest'=>hash('sha256',$html),
            'bundleDigest'=>hash('sha256',$html."\0".$manifest),
        ];
        self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$args));
        $extra=$dir.'/extra.html';
        try {
            file_put_contents($extra,'untrusted');
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
                'Additional disk files invalidate exact package inventory');
        } finally {
            @unlink($extra);
        }
        file_put_contents($dir.'/release-manifest.json',$manifest."\n");
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
            'A modified manifest cannot match the prior pinned digest');
    }

    public function test_read_only_evidence_json_is_exact_and_never_authorizes_publication(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        foreach (['onepage','landing_page'] as $product) {
            self::assertSame(0,Artisan::call('sites:package-fixture',
                ['product'=>$product]));
            $dir=$this->path($product);
            $html=(string)file_get_contents($dir.'/index.html');
            $manifest=(string)file_get_contents($dir.'/release-manifest.json');
            $arguments=[
                'product'=>$product,
                'artifactDigest'=>hash('sha256',$html),
                'bundleDigest'=>hash('sha256',$html.chr(0).$manifest),
                '--evidence-json'=>true,
            ];
            self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$arguments));
            $report=json_decode(trim(Artisan::output()),true,16,JSON_THROW_ON_ERROR);
            self::assertSame('sites.synthetic.private-bundle-evidence.v1',
                $report['schemaVersion']);
            self::assertTrue($report['syntheticOnly']);
            self::assertTrue($report['readOnly']);
            self::assertTrue($report['verifiedLocalBytes']);
            self::assertFalse($report['trustedPins']);
            self::assertFalse($report['officialClientAcceptance']);
            self::assertFalse($report['releaseAuthorized']);
            self::assertSame($product,$report['product']);
            self::assertSame(hash('sha256',$html),$report['artifactDigest']);
            self::assertSame($arguments['bundleDigest'],$report['bundleDigest']);
            self::assertSame([
                ['name'=>'index.html','bytes'=>strlen($html),'sha256'=>hash('sha256',$html)],
                ['name'=>'release-manifest.json','bytes'=>strlen($manifest),
                 'sha256'=>hash('sha256',$manifest)],
            ],$report['files']);
            self::assertSame($html,file_get_contents($dir.'/index.html'));
            self::assertSame($manifest,file_get_contents($dir.'/release-manifest.json'));
        }
    }

    public function test_opt_in_structural_qa_evidence_checks_both_products_without_granting_release(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        foreach (['onepage','landing_page'] as $product) {
            self::assertSame(0,Artisan::call('sites:package-fixture',
                ['product'=>$product]));
            $directory=$this->path($product);
            $html=(string)file_get_contents($directory.'/index.html');
            $manifest=(string)file_get_contents($directory.'/release-manifest.json');
            $arguments=[
                'product'=>$product,
                'artifactDigest'=>hash('sha256',$html),
                'bundleDigest'=>hash('sha256',$html.chr(0).$manifest),
            ];
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
                $arguments+['--include-qa'=>true]),
                'Standalone QA flag must not degrade to an ordinary successful verification');
            self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',
                $arguments+['--evidence-json'=>true,'--include-qa'=>true]));
            $first=trim(Artisan::output());
            self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',
                $arguments+['--evidence-json'=>true,'--include-qa'=>true]));
            self::assertSame($first,trim(Artisan::output()),
                'QA evidence must be byte-for-byte deterministic');
            $report=json_decode($first,true,16,JSON_THROW_ON_ERROR);
            self::assertSame('sites.synthetic.private-bundle-evidence.v1',
                $report['schemaVersion']);
            self::assertSame($product,$report['product']);
            self::assertTrue($report['verifiedLocalBytes']);
            self::assertFalse($report['trustedPins']);
            self::assertFalse($report['officialClientAcceptance']);
            self::assertFalse($report['releaseAuthorized']);
            self::assertTrue($report['structuralQa']['passed']);
            self::assertTrue($report['structuralQa']['humanReviewRequired']);
            self::assertFalse($report['structuralQa']['wcagCertified']);
            self::assertCount(10,$report['structuralQa']['checks']);
            foreach ($report['structuralQa']['checks'] as $name=>$passed) {
                self::assertIsString($name);
                self::assertTrue($passed);
            }
            self::assertSame($html,file_get_contents($directory.'/index.html'));
            self::assertSame($manifest,file_get_contents($directory.'/release-manifest.json'));
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',
                array_replace($arguments,[
                    'bundleDigest'=>str_repeat('0',64),
                    '--evidence-json'=>true,'--include-qa'=>true,
                ])), 'Wrong digest must deny QA evidence before inspection');
            self::assertStringNotContainsString('"structuralQa":',Artisan::output(),
                'A failed verification cannot claim a passed QA report');
        }
    }

    public function test_private_writer_fails_fast_when_another_process_holds_product_lock(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        $private=storage_path('app/private');
        if (!is_dir($private)) mkdir($private,0700,true);
        $lock=\Imagine\Sites\Production\SyntheticPrivateBundleLockV1::acquire(
            $private,'onepage',true
        );
        self::assertNotNull($lock);
        try {
            self::assertSame(1,Artisan::call('sites:package-fixture',
                ['product'=>'onepage']));
            self::assertDirectoryDoesNotExist($this->path('onepage'));
            $leftovers=glob($private.'/.sites-f1-24-onepage-*.tmp');
            self::assertSame([],$leftovers===false?[]:$leftovers);
            // Independent products have separate fixed-name locks.
            self::assertSame(0,Artisan::call('sites:package-fixture',
                ['product'=>'landing_page']));
        } finally {
            $lock->release();
        }
        self::assertSame(0,Artisan::call('sites:package-fixture',
            ['product'=>'onepage']));
    }

    public function test_read_only_reader_fails_closed_while_writer_lock_is_held(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',
            ['product'=>'onepage']));
        $dir=$this->path('onepage');
        $html=(string)file_get_contents($dir.'/index.html');
        $json=(string)file_get_contents($dir.'/release-manifest.json');
        $args=[
            'product'=>'onepage',
            'artifactDigest'=>hash('sha256',$html),
            'bundleDigest'=>hash('sha256',$html."\0".$json),
        ];
        $lock=\Imagine\Sites\Production\SyntheticPrivateBundleLockV1::acquire(
            storage_path('app/private'),'onepage',false
        );
        self::assertNotNull($lock);
        try {
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
                'A concurrent read must refuse rather than inspect staging');
        } finally {
            $lock->release();
        }
        self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$args));
        self::assertSame($html,file_get_contents($dir.'/index.html'));
        self::assertSame($json,file_get_contents($dir.'/release-manifest.json'));
    }

    public function test_read_only_reader_never_creates_a_missing_product_lock(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        $path=storage_path('app/private/.sites-f1-24-onepage.lock');
        self::assertFileDoesNotExist($path);
        self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',[
            'product'=>'onepage',
            'artifactDigest'=>str_repeat('a',64),
            'bundleDigest'=>str_repeat('b',64),
        ]));
        self::assertFileDoesNotExist($path);
        self::assertDirectoryDoesNotExist($this->path('onepage'));
    }

    public function test_writer_denies_preexisting_insecure_lock_without_chmod(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        $private=storage_path('app/private');
        if (!is_dir($private)) mkdir($private,0700,true);
        $lock=$private.'/.sites-f1-24-onepage.lock';
        file_put_contents($lock,'');
        chmod($lock,0644);
        clearstatcache(true,$lock);
        self::assertSame(1,Artisan::call('sites:package-fixture',
            ['product'=>'onepage']));
        clearstatcache(true,$lock);
        self::assertSame(0644,fileperms($lock) & 0777,
            'Writer must not alter permissions of a potentially substituted path');
        self::assertDirectoryDoesNotExist($this->path('onepage'));
        unlink($lock);
        $originalMask=umask(0000);
        try {
            self::assertSame(0,Artisan::call('sites:package-fixture',
                ['product'=>'onepage']),
                'New private lock must be created safely even with permissive umask');
        } finally {
            umask($originalMask);
        }
        clearstatcache(true,$lock);
        self::assertSame(0600,fileperms($lock) & 0777);
        self::assertFileExists($this->path('onepage').'/index.html');
    }

    public function test_writer_rejects_symlink_lock_without_creating_bundle(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        $private=storage_path('app/private');
        if (!is_dir($private)) mkdir($private,0700,true);
        $target=$private.'/.f300-symlink-canary';
        $path=$private.'/.sites-f1-24-onepage.lock';
        file_put_contents($target,'untouched');
        try {
            self::assertTrue(symlink($target,$path));
            self::assertSame(1,Artisan::call('sites:package-fixture',
                ['product'=>'onepage']));
            self::assertSame('untouched',file_get_contents($target));
            self::assertDirectoryDoesNotExist($this->path('onepage'));
        } finally {
            @unlink($path);
            @unlink($target);
        }
    }

    public function test_private_verifier_refuses_symlink_hardlink_and_oversized_readback(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        putenv('SITES_SYNTHETIC_VERIFY=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',
            ['product'=>'onepage']));
        $dir=$this->path('onepage');
        $index=$dir.'/index.html';
        $manifest=$dir.'/release-manifest.json';
        $html=(string)file_get_contents($index);
        $json=(string)file_get_contents($manifest);
        $args=[
            'product'=>'onepage',
            'artifactDigest'=>hash('sha256',$html),
            'bundleDigest'=>hash('sha256',$html."\0".$json),
        ];

        $backup=storage_path('app/private/.f301-onepage-index-backup');
        self::assertTrue(rename($index,$backup));
        try {
            self::assertTrue(symlink($backup,$index));
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
                'A path swap to symlink must be denied before reading');
            unlink($index);
            self::assertTrue(link($backup,$index));
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
                'A hardlinked payload must not be accepted as a private file');
        } finally {
            if (is_file($index) || is_link($index)) @unlink($index);
            if (is_file($backup)) rename($backup,$index);
        }
        self::assertSame($html,file_get_contents($index));
        self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$args));

        file_put_contents($manifest,str_repeat('x',8193));
        try {
            self::assertSame(1,Artisan::call('sites:verify-fixture-bundle',$args),
                'Oversized manifest rejected before full read');
        } finally {
            file_put_contents($manifest,$json);
        }
        self::assertSame(0,Artisan::call('sites:verify-fixture-bundle',$args));
    }

    public function test_landing_is_supported_with_no_executable_forms(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(0,Artisan::call('sites:package-fixture',['product'=>'landing_page']));
        $html=(string)file_get_contents($this->path('landing_page').'/index.html');
        self::assertStringContainsString('data-site-product="landing-page"',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringNotContainsString('<script',$html);
    }

    public function test_unsafe_product_path_refused(): void
    {
        putenv('SITES_SYNTHETIC_BUNDLE=1');
        self::assertSame(1,Artisan::call('sites:package-fixture',['product'=>'../../outside']));
        self::assertDirectoryDoesNotExist($this->path('onepage'));
    }
}
