<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-174: verify edited review package is durable and not publishable. */
final class SyntheticEditedPrivateBundleTest extends TestCase
{
    private function directory(string $product,string $preset):string
    {
        return storage_path('app/private/sites-f1-174-'.$product.'-'.$preset.'-edited');
    }

    private function cleanDirectory(string $path):void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path))return;
        foreach([
            'index.html','internal-review-dossier.json',
            'internal-review-draft.json','release-manifest.json',
        ] as $file) {
            @unlink($path.'/'.$file);
        }
        @rmdir($path);
    }

    protected function setUp():void
    {
        putenv('SITES_SYNTHETIC_EDITORIAL_REVIEW=1');
        putenv('SITES_F174_EDITORIAL_PACKAGE=1');
        parent::setUp();
        // Fresh non-root Docker images do not ship storage/app/private.
        // Create only the fixed, private test root; never follow a symlink
        // or silently depend on a previous unrelated test's output.
        $root=storage_path('app/private');
        if (is_link($root) ||
            (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root)) {
            throw new \\RuntimeException('Unsafe or unavailable private CI fixture root.');
        }
        if (!chmod($root,0700)) {
            throw new \\RuntimeException('Private CI fixture root requires mode 0700.');
        }
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                $this->cleanDirectory($this->directory($product,$preset));
            }
        }
        putenv('SITES_SYNTHETIC_EDITORIAL_REVIEW');
        putenv('SITES_F174_EDITORIAL_PACKAGE');
        parent::tearDown();
    }

    public function test_all_six_edited_drafts_have_verified_pending_review_files():void
    {
        $digests=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                self::assertSame(0,Artisan::call(
                    'sites:export-editorial-package-fixture',
                    ['product'=>$product,'preset'=>$preset]
                ));
                $path=$this->directory($product,$preset);
                $proof=SyntheticPrivateDraftVerifier::verifyDirectory($path);
                self::assertTrue($proof['verifiedPrivateLabFiles']);
                self::assertSame('pending',$proof['reviewerDecisionStatus']);
                self::assertFalse($proof['publishable']);
                self::assertFalse($proof['officialClientAcceptance']);
                self::assertSame('tenant-f174',$proof['tenantRef']);
                self::assertSame('site-f174',$proof['siteRef']);
                self::assertSame('release-f174-after-'.$product.'-'.$preset,
                    $proof['releaseRef']);
                self::assertSame(64,strlen($proof['artifactDigest']));

                $html=(string)file_get_contents($path.'/index.html');
                self::assertSame(hash('sha256',$html),$proof['artifactDigest']);
                self::assertStringContainsString('<section class="process"',$html);
                self::assertStringContainsString('<section class="faq"',$html);
                self::assertStringContainsString('<section class="highlights"',$html);
                self::assertStringContainsString('O texto demonstrativo foi editado',$html);
                self::assertStringContainsString('Uma alteração editorial fictícia gera outra versão',$html);
                self::assertStringNotContainsString('<form',$html);
                self::assertStringNotContainsString('<script',$html);
                self::assertStringContainsString('noindex, nofollow',$html);

                $summary=json_decode(
                    (string)file_get_contents($path.'/internal-review-draft.json'),
                    true,16,JSON_THROW_ON_ERROR
                );
                $dossier=json_decode(
                    (string)file_get_contents($path.'/internal-review-dossier.json'),
                    true,16,JSON_THROW_ON_ERROR
                );
                $manifest=json_decode(
                    (string)file_get_contents($path.'/release-manifest.json'),
                    true,16,JSON_THROW_ON_ERROR
                );
                self::assertSame('internal_review',$summary['workflowState']);
                self::assertTrue($summary['internalReviewRequired']);
                self::assertFalse($summary['officialClientAcceptance']);
                self::assertFalse($summary['publishable']);
                self::assertSame($proof['artifactDigest'],$manifest['artifactDigest']);
                self::assertSame('pending',$dossier['reviewerDecisionStatus']);
                self::assertFalse($dossier['publishable']);
                self::assertTrue($dossier['portalAuthorityRequired']);
                self::assertSame($proof['dossierDigest'],
                    hash('sha256',(string)file_get_contents(
                        $path.'/internal-review-dossier.json'
                    )));
                $digests[]=$proof['artifactDigest'];
            }
        }
        self::assertCount(6,array_unique($digests),
            'Each product and preset has a distinct edited private HTML identity');
    }

    public function test_flag_and_product_gates_default_deny():void
    {
        foreach(['SITES_SYNTHETIC_EDITORIAL_REVIEW','SITES_F174_EDITORIAL_PACKAGE'] as $flag) {
            putenv($flag);
            try {
                self::assertSame(1,Artisan::call('sites:export-editorial-package-fixture'));
            } finally {
                putenv($flag.'=1');
            }
        }
        foreach([
            ['blog','classic'],['onepage','custom'],
            ['landing_page','unknown'],
        ] as [$product,$preset]) {
            self::assertSame(1,Artisan::call(
                'sites:export-editorial-package-fixture',
                ['product'=>$product,'preset'=>$preset]
            ));
        }
        self::assertDirectoryDoesNotExist($this->directory('onepage','classic'));
    }

    public function test_repeat_export_denied_without_mutating_existing_files():void
    {
        self::assertSame(0,Artisan::call('sites:export-editorial-package-fixture'));
        $directory=$this->directory('onepage','classic');
        $before=(string)file_get_contents($directory.'/index.html');
        self::assertSame(1,Artisan::call('sites:export-editorial-package-fixture'));
        self::assertSame($before,
            (string)file_get_contents($directory.'/index.html'));
        self::assertTrue(
            SyntheticPrivateDraftVerifier::verifyDirectory($directory)['verifiedPrivateLabFiles']
        );
    }

    public function test_symlink_destination_denied_and_tampered_content_cannot_verify():void
    {
        $destination=$this->directory('onepage','classic');
        $marker=storage_path('app/private/f174-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        self::assertTrue(symlink($marker,$destination));
        try {
            self::assertSame(1,Artisan::call('sites:export-editorial-package-fixture'));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        } finally {
            @unlink($destination);
            @unlink($marker);
        }
        self::assertSame(0,Artisan::call('sites:export-editorial-package-fixture'));
        file_put_contents($destination.'/index.html','tampered');
        $rejected=false;
        try {SyntheticPrivateDraftVerifier::verifyDirectory($destination);}
        catch(\DomainException){$rejected=true;}
        self::assertTrue($rejected,'Manual mutation must fail independent readback');
    }
}
