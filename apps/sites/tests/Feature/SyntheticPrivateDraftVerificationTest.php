<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use Tests\TestCase;

/** F1-86: validate the actual on-disk files, not the in-memory builder. */
final class SyntheticPrivateDraftVerificationTest extends TestCase
{
    private const NAMES = [
        'index.html', 'internal-review-dossier.json',
        'internal-review-draft.json', 'release-manifest.json',
    ];

    private function directory(string $product='onepage',string $preset='classic',
        string $variant='basic'):string
    {
        return storage_path('app/private/sites-f1-72-'.$product.'-'.$preset.'-'.$variant);
    }

    private function create(string $product='onepage',string $preset='classic',
        string $variant='basic'):string
    {
        putenv('SITES_SYNTHETIC_DRAFT=1');
        self::assertSame(0,Artisan::call('sites:assemble-draft-fixture',[
            'product'=>$product,'preset'=>$preset,'variant'=>$variant,
        ]));
        return $this->directory($product,$preset,$variant);
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['basic','focused'] as $variant) {
                    $dir=$this->directory($product,$preset,$variant);
                    if (is_link($dir)) {@unlink($dir);continue;}
                    if (!is_dir($dir))continue;
                    foreach(scandir($dir) ?: [] as $name) {
                        if($name==='.'||$name==='..')continue;
                        @unlink($dir.'/'.$name);
                    }
                    @rmdir($dir);
                }
            }
        }
        putenv('SITES_SYNTHETIC_DRAFT');
        putenv('SITES_SYNTHETIC_VERIFY');
        parent::tearDown();
    }

    public function test_all_twelve_private_drafts_are_reverified_from_disk():void
    {
        putenv('SITES_SYNTHETIC_VERIFY=1');
        $digests=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['basic','focused'] as $variant) {
                    $dir=$this->create($product,$preset,$variant);
                    $before=[];
                    foreach(self::NAMES as $name) {
                        $before[$name]=hash_file('sha256',$dir.'/'.$name);
                    }
                    $proof=SyntheticPrivateDraftVerifier::verifyDirectory($dir);
                    self::assertSame('sites.synthetic.private-draft-verification.v1',
                        $proof['schemaVersion']);
                    self::assertSame('release-f72-'.$product.'-'.$preset.'-'.$variant,
                        $proof['releaseRef']);
                    self::assertSame(hash_file('sha256',$dir.'/index.html'),
                        $proof['artifactDigest']);
                    self::assertSame(hash_file('sha256',$dir.'/internal-review-dossier.json'),
                        $proof['dossierDigest']);
                    self::assertTrue($proof['verifiedPrivateLabFiles']);
                    self::assertTrue($proof['externalPortalAuthorityRequired']);
                    self::assertSame('pending',$proof['reviewerDecisionStatus']);
                    self::assertFalse($proof['officialClientAcceptance']);
                    self::assertFalse($proof['publishable']);
                    self::assertSame(2,$proof['workflowRevision']);
                    self::assertSame(0,Artisan::call('sites:verify-draft-fixture',[
                        'product'=>$product,'preset'=>$preset,'variant'=>$variant,
                    ]));
                    $output=Artisan::output();
                    self::assertStringContainsString('HUMAN REVIEW PENDING',$output);
                    self::assertStringContainsString('publishable: NO',$output);
                    foreach(self::NAMES as $name) {
                        self::assertSame($before[$name],hash_file('sha256',$dir.'/'.$name),
                            'Verification must never modify draft files');
                    }
                    $digests[]=$proof['artifactDigest'];
                }
            }
        }
        self::assertCount(12,array_unique($digests));
    }

    public function test_edited_or_noncanonical_files_fail_closed():void
    {
        $dir=$this->create();
        putenv('SITES_SYNTHETIC_VERIFY=1');
        foreach(self::NAMES as $name) {
            $path=$dir.'/'.$name;
            $original=(string)file_get_contents($path);
            // A single extra newline violates either hash or canonical JSON.
            file_put_contents($path,$original."\n");
            self::assertSame(1,Artisan::call('sites:verify-draft-fixture'));
            file_put_contents($path,$original);
            self::assertSame(0,Artisan::call('sites:verify-draft-fixture'));
        }

        // A consistent checksum alone cannot authorize a review or release.
        $summaryPath=$dir.'/internal-review-draft.json';
        $summaryOriginal=(string)file_get_contents($summaryPath);
        $summary=json_decode($summaryOriginal,true,16,JSON_THROW_ON_ERROR);
        $summary['publishable']=true;
        file_put_contents($summaryPath,
            json_encode($summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
        self::assertSame(1,Artisan::call('sites:verify-draft-fixture'));
        file_put_contents($summaryPath,$summaryOriginal);

        $dossierPath=$dir.'/internal-review-dossier.json';
        $dossierOriginal=(string)file_get_contents($dossierPath);
        $dossier=json_decode($dossierOriginal,true,16,JSON_THROW_ON_ERROR);
        $dossier['reviewerDecisionStatus']='approved';
        $dossierBytes=json_encode($dossier,
            JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        file_put_contents($dossierPath,$dossierBytes);
        $summary=json_decode($summaryOriginal,true,16,JSON_THROW_ON_ERROR);
        $summary['reviewDossierDigest']=hash('sha256',$dossierBytes);
        file_put_contents($summaryPath,
            json_encode($summary,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
        self::assertSame(1,Artisan::call('sites:verify-draft-fixture'),
            'Pending review cannot be forged even with a recomputed checksum');
        file_put_contents($summaryPath,$summaryOriginal);
        file_put_contents($dossierPath,$dossierOriginal);
        self::assertSame(0,Artisan::call('sites:verify-draft-fixture'));
    }

    public function test_symlinks_extra_files_and_wrong_flags_are_denied():void
    {
        putenv('SITES_SYNTHETIC_VERIFY');
        self::assertSame(1,Artisan::call('sites:verify-draft-fixture'));
        $dir=$this->create();
        self::assertSame(1,Artisan::call('sites:verify-draft-fixture'));
        putenv('SITES_SYNTHETIC_VERIFY=1');

        foreach([
            ['product'=>'../admin','preset'=>'classic','variant'=>'basic'],
            ['product'=>'onepage','preset'=>'../../unsafe','variant'=>'basic'],
            ['product'=>'onepage','preset'=>'classic','variant'=>'outside'],
        ] as $input) {
            self::assertSame(1,Artisan::call('sites:verify-draft-fixture',$input));
        }
        self::assertSame(0,Artisan::call('sites:verify-draft-fixture'));

        $extra=$dir.'/unexpected.txt';
        file_put_contents($extra,'not part of approved inventory');
        self::assertSame(1,Artisan::call('sites:verify-draft-fixture'));
        unlink($extra);

        $file=$dir.'/internal-review-dossier.json';
        $backup=$dir.'/dossier-backup.txt';
        rename($file,$backup);
        self::assertTrue(symlink($backup,$file));
        self::assertSame(1,Artisan::call('sites:verify-draft-fixture'));
        unlink($file);
        rename($backup,$file);

        self::assertSame(0,Artisan::call('sites:verify-draft-fixture'));
        $before=hash_file('sha256',$dir.'/index.html');
        self::assertSame(1,Artisan::call('sites:verify-draft-fixture',[
            'product'=>'landing_page','preset'=>'classic','variant'=>'basic',
        ]));
        self::assertSame($before,hash_file('sha256',$dir.'/index.html'));
    }
}
