<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-205 — only private, synthetic, unapproved composite design fixtures. */
final class SyntheticCompositeVisualPrivateBundleTest extends TestCase
{
    private const EXAMPLES=[
        ['onepage','classic','services_process_faq','default','off'],
        ['onepage','modern','faq_process_services','ocean','on'],
        ['onepage','warm','process_faq_services','plum','off'],
        ['onepage','classic','faq_services_process','forest','on'],
        ['landing_page','classic','services_process_faq','default','off'],
        ['landing_page','modern','faq_process_services','ocean','off'],
        ['landing_page','warm','process_faq_services','plum','off'],
        ['landing_page','classic','faq_services_process','forest','off'],
    ];

    protected function setUp():void
    {
        putenv('SITES_F205_VISUAL_PACKAGE=1');
        parent::setUp();
        $root=storage_path('app/private');
        if (is_link($root) ||
            (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Unsafe synthetic private storage root');
        }
    }

    protected function tearDown():void
    {
        foreach(self::EXAMPLES as $choice) {
            $path=$this->location(...$choice);
            if(is_link($path)) {
                @unlink($path);
                continue;
            }
            if (!is_dir($path))continue;
            foreach([
                'index.html','internal-review-dossier.json',
                'internal-review-draft.json','release-manifest.json',
            ] as $name)@unlink($path.'/'.$name);
            @rmdir($path);
        }
        putenv('SITES_F205_VISUAL_PACKAGE');
        parent::tearDown();
    }

    private function location(
        string $product,string $preset,string $order,
        string $palette,string $navigation
    ):string {
        return storage_path('app/private/sites-f1-205-'.$product.'-'.$preset.
            '-'.$order.'-'.$palette.'-'.$navigation);
    }

    private function export(
        string $product,string $preset,string $order,
        string $palette,string $navigation
    ):int {
        return Artisan::call('sites:export-visual-fixture',[
            'product'=>$product,'preset'=>$preset,'order'=>$order,
            'palette'=>$palette,'navigation'=>$navigation,
        ]);
    }

    public function test_private_exports_have_exact_four_files_and_pending_review():void
    {
        $hashes=[];
        foreach(self::EXAMPLES as $choice) {
            self::assertSame(0,$this->export(...$choice));
            $path=$this->location(...$choice);
            $proof=Verifier::verifyDirectory($path);
            self::assertTrue($proof['verifiedPrivateLabFiles']);
            self::assertSame('tenant-f205',$proof['tenantRef']);
            self::assertSame('site-f205',$proof['siteRef']);
            self::assertSame(
                'release-f205-'.implode('-',$choice),$proof['releaseRef']
            );
            self::assertSame('pending',$proof['reviewerDecisionStatus']);
            self::assertFalse($proof['publishable']);
            self::assertFalse($proof['officialClientAcceptance']);
            self::assertTrue($proof['externalPortalAuthorityRequired']);

            $html=(string)file_get_contents($path.'/index.html');
            self::assertSame(hash('sha256',$html),$proof['artifactDigest']);
            self::assertStringContainsString('noindex, nofollow',$html);
            self::assertStringNotContainsString('<form',$html);
            self::assertStringNotContainsString('<script',$html);
            foreach(['highlights','process','faq'] as $section) {
                self::assertSame(1,substr_count(
                    $html,'<section class="'.$section.'"'
                ));
            }
            foreach(['#solucoes','#etapas','#perguntas'] as $anchor) {
                if ($choice[4]==='on') {
                    self::assertStringContainsString('<a href="'.$anchor.'">',$html);
                } else {
                    self::assertStringNotContainsString('<a href="'.$anchor.'">',$html);
                }
            }
            if ($choice[3]==='default') {
                self::assertStringNotContainsString(
                    '.hero .eyebrow { color: #ffffff; opacity: 1; }',$html
                );
            } else {
                self::assertStringContainsString(
                    '.hero .eyebrow { color: #ffffff; opacity: 1; }',$html
                );
            }
            $summary=json_decode(
                (string)file_get_contents($path.'/internal-review-draft.json'),
                true,16,JSON_THROW_ON_ERROR
            );
            self::assertSame('internal_review',$summary['workflowState']);
            self::assertFalse($summary['publishable']);
            self::assertTrue($summary['internalReviewRequired']);
            $hashes[]=$proof['artifactDigest'];
        }
        self::assertCount(count(self::EXAMPLES),array_unique($hashes));
    }

    public function test_invalid_options_missing_flag_and_repeat_export_fail_closed():void
    {
        foreach([
            ['blog','classic','services_process_faq','default','off'],
            ['landing_page','classic','services_process_faq','ocean','on'],
            ['onepage','custom','services_process_faq','default','off'],
            ['onepage','classic','unknown','default','off'],
            ['onepage','classic','services_process_faq','javascript:','off'],
            ['onepage','classic','services_process_faq','default','unknown'],
        ] as $bad)self::assertSame(1,$this->export(...$bad));

        putenv('SITES_F205_VISUAL_PACKAGE');
        try {self::assertSame(1,$this->export(...self::EXAMPLES[0]));}
        finally {putenv('SITES_F205_VISUAL_PACKAGE=1');}

        $valid=self::EXAMPLES[0];
        self::assertSame(0,$this->export(...$valid));
        $path=$this->location(...$valid);
        $html=(string)file_get_contents($path.'/index.html');
        self::assertSame(1,$this->export(...$valid));
        self::assertSame($html,(string)file_get_contents($path.'/index.html'));
    }

    public function test_symlink_destination_denied_and_tampering_detected():void
    {
        $choice=self::EXAMPLES[1];
        $destination=$this->location(...$choice);
        $marker=storage_path('app/private/f205-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        self::assertTrue(symlink($marker,$destination));
        try {
            self::assertSame(1,$this->export(...$choice));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        }finally {
            @unlink($destination);
            @unlink($marker);
        }

        self::assertSame(0,$this->export(...$choice));
        file_put_contents($destination.'/index.html','tampered');
        $rejected=false;
        try {Verifier::verifyDirectory($destination);}
        catch(\DomainException){$rejected=true;}
        self::assertTrue($rejected,'Altered private review HTML cannot verify');
    }
}
