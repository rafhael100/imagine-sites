<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use Imagine\Sites\Production\SyntheticPrivateSeoSnippetReadbackV1;
use Imagine\Sites\Production\SyntheticSeoCopyFixtureV1;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * F1-223: export four real private files for each fixed copy and social mode.
 * All records are fictional, no client, public URL or operational SEO index.
 */
final class SyntheticSeoCopyPrivateExportTest extends TestCase
{
    private const ROUTE='/_lab/sites/v1/seo-copy-review';
    private const FLAGS=[
        'SITES_F223_SEO_EXPORT',
        'SITES_SYNTHETIC_HTTP',
        'SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW',
        'SITES_F222_SEO_COPY_REVIEW',
    ];

    private function location(
        string $product,string $preset,string $seo,string $social
    ):string {
        return storage_path('app/private/sites-f1-223-'.$product.'-'.$preset.
            '-'.$seo.'-'.$social);
    }

    private function export(
        string $product,string $preset,string $seo,string $social
    ):int {
        return Artisan::call('sites:export-seo-copy-fixture',[
            'product'=>$product,'preset'=>$preset,
            'seo'=>$seo,'social'=>$social,
        ]);
    }

    protected function setUp():void
    {
        foreach(self::FLAGS as $flag)putenv($flag.'=1');
        parent::setUp();
        $root=storage_path('app/private');
        if(is_link($root) || (!is_dir($root) &&
            !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Unsafe private CI test directory.');
        }
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['default','custom'] as $seo) {
                    foreach(['off','on'] as $social) {
                        $path=$this->location($product,$preset,$seo,$social);
                        if(is_link($path)) {
                            @unlink($path);
                            continue;
                        }
                        if(!is_dir($path))continue;
                        foreach([
                            'index.html','internal-review-dossier.json',
                            'internal-review-draft.json','release-manifest.json',
                        ] as $file)@unlink($path.'/'.$file);
                        @rmdir($path);
                    }
                }
            }
        }
        foreach(self::FLAGS as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_all_24_fixed_seo_modes_export_as_unapproved_exact_preview():void
    {
        $unique=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['off','on'] as $social) {
                    $perCopy=[];
                    foreach(['default','custom'] as $seo) {
                        self::assertSame(0,$this->export(
                            $product,$preset,$seo,$social
                        ),'Allowed SEO mode must generate private review bytes');
                        $directory=$this->location($product,$preset,$seo,$social);
                        $proof=SyntheticPrivateDraftVerifier::verifyDirectory($directory);
                        self::assertTrue($proof['verifiedPrivateLabFiles']);
                        self::assertSame('tenant-f223',$proof['tenantRef']);
                        self::assertSame('site-f223',$proof['siteRef']);
                        self::assertSame(
                            'release-f223-'.$product.'-'.$preset.'-'.$seo.'-'.$social,
                            $proof['releaseRef']
                        );
                        self::assertSame('pending',$proof['reviewerDecisionStatus']);
                        self::assertFalse($proof['publishable']);
                        self::assertFalse($proof['officialClientAcceptance']);
                        $files=[];
                        foreach([
                            'index.html','internal-review-dossier.json',
                            'internal-review-draft.json','release-manifest.json'
                        ] as $name) {
                            $files[$name]=(string)file_get_contents(
                                $directory.'/'.$name
                            );
                        }
                        self::assertSame(hash('sha256',$files['index.html']),
                            $proof['artifactDigest']);
                        self::assertStringContainsString(
                            '<meta name="robots" content="noindex, nofollow">',
                            $files['index.html']
                        );
                        self::assertStringNotContainsString('rel="canonical"',
                            $files['index.html']);
                        self::assertStringNotContainsString('og:url',
                            $files['index.html']);
                        self::assertStringNotContainsString('<form',
                            $files['index.html']);
                        self::assertStringNotContainsString('<script',
                            $files['index.html']);
                        self::assertSame($social==='on'?12:5,
                            substr_count($files['index.html'],'<meta '));

                        $fixture=json_decode((string)file_get_contents(
                            base_path('../../fixtures/sites/f1-170-process-copy.synthetic.json')
                        ),true,16,JSON_THROW_ON_ERROR);
                        $candidate=SyntheticSeoCopyFixtureV1::build(
                            $fixture,$product,$preset,$seo,$social
                        );
                        $readback=SyntheticPrivateSeoSnippetReadbackV1::inspect(
                            $files,$candidate->draft->brief
                        );
                        self::assertSame($proof['releaseRef'],$readback->releaseRef);
                        self::assertSame($proof['artifactDigest'],$readback->artifactDigest);
                        self::assertFalse($readback->canIndex());
                        self::assertFalse($readback->canPublish());
                        self::assertFalse($readback->canSendToSeoEngine());
                        self::assertFalse($candidate->canApproveForClient());

                        // The actual Laravel reviewer renders the same HTML
                        // even though its synthetic releaseRef differs.
                        $res=$this->get(self::ROUTE.'?product='.$product.
                            '&device=desktop&preset='.$preset.'&seo='.$seo.
                            '&social='.$social)->assertOk();
                        $outer=(string)$res->getContent();
                        self::assertSame(1,preg_match(
                            '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                            $outer,$matched
                        ));
                        self::assertSame($proof['artifactDigest'],$matched[1]);
                        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',
                            $outer,$frame
                        ));
                        self::assertSame(
                            $files['index.html'],
                            html_entity_decode($frame[1],ENT_QUOTES|ENT_HTML5,'UTF-8')
                        );
                        self::assertStringNotContainsString('https://',$outer);
                        $snippet=$readback->snippet;
                        if ($seo==='custom') {
                            self::assertSame(
                                SyntheticSeoCopyFixtureV1::CUSTOM_TITLE,$snippet->title
                            );
                            self::assertSame(
                                SyntheticSeoCopyFixtureV1::CUSTOM_DESCRIPTION,
                                $snippet->description
                            );
                            self::assertSame(
                                ['seoTitle','seoDescription'],
                                $candidate->revisionPlan?->changedFields
                            );
                            self::assertSame(null,
                                $candidate->revisionPlan?->draftWorkflow->artifactDigest
                            );
                        }else{
                            self::assertNull($candidate->revisionPlan);
                        }
                        $perCopy[$seo]=$proof['artifactDigest'];
                        $unique[]=$proof['artifactDigest'];
                    }
                    self::assertNotSame($perCopy['default'],$perCopy['custom']);
                }
            }
        }
        self::assertCount(24,array_unique($unique),
            'All product/preset/social/SEO choices are unique reviewed HTML');
    }

    public function test_invalid_options_and_flags_deny_all_writes():void
    {
        foreach([
            ['blog','classic','custom','on'],
            ['onepage','vip','default','off'],
            ['onepage','classic','noindex','off'],
            ['onepage','classic','custom','remote'],
            ['../../etc','classic','custom','on'],
        ] as [$p,$preset,$seo,$social]) {
            self::assertSame(1,$this->export($p,$preset,$seo,$social));
        }
        putenv('SITES_F223_SEO_EXPORT');
        try{
            self::assertSame(1,$this->export(
                'onepage','classic','default','off'
            ));
        }finally{putenv('SITES_F223_SEO_EXPORT=1');}
        self::assertDirectoryDoesNotExist(
            $this->location('onepage','classic','default','off')
        );
    }

    public function test_replay_and_modified_bytes_fail_independent_readback():void
    {
        self::assertSame(0,$this->export('onepage','classic','custom','on'));
        $path=$this->location('onepage','classic','custom','on');
        $source=(string)file_get_contents($path.'/index.html');
        self::assertSame(1,$this->export('onepage','classic','custom','on'));
        self::assertSame($source,(string)file_get_contents($path.'/index.html'));
        $manifest=$path.'/release-manifest.json';
        file_put_contents($manifest,'{"forged":true}');
        $rejected=false;
        try {SyntheticPrivateDraftVerifier::verifyDirectory($path);}
        catch(\DomainException){$rejected=true;}
        self::assertTrue($rejected,'Modified manifest cannot be accepted');
    }

    public function test_symlink_target_is_rejected_without_touching_marker():void
    {
        $marker=storage_path('app/private/f223-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        $target=$this->location('landing_page','warm','custom','on');
        self::assertTrue(symlink($marker,$target));
        try {
            self::assertSame(1,$this->export(
                'landing_page','warm','custom','on'
            ));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        }finally {
            @unlink($target);
            @unlink($marker);
        }
    }
}
