<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-219: read-only SEO inspection of already exported private review files. */
final class SyntheticPrivateSeoCliTest extends TestCase
{
    private const SAMPLES=[
        ['onepage','classic','services_process_faq','default','off','off'],
        ['onepage','modern','faq_process_services','forest','on','on'],
        ['landing_page','warm','process_faq_services','plum','off','on'],
        ['landing_page','classic','faq_services_process','ocean','off','off'],
    ];

    protected function setUp():void
    {
        putenv('SITES_F205_VISUAL_PACKAGE=1');
        putenv('SITES_F219_SEO_READBACK=1');
        parent::setUp();
        $root=storage_path('app/private');
        if (is_link($root) ||
            (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Private synthetic storage root denied.');
        }
    }

    private function folder(array $choices):string
    {
        [$product,$preset,$order,$palette,$navigation,$social]=$choices;
        return storage_path('app/private/sites-f1-205-').
            $product.'-'.$preset.'-'.$order.'-'.$palette.'-'.$navigation.
            ($social==='on'?'-social-on':'');
    }

    private function callFixture(string $command,array $choices):int
    {
        [$product,$preset,$order,$palette,$navigation,$social]=$choices;
        return Artisan::call($command,[
            'product'=>$product,'preset'=>$preset,'order'=>$order,
            'palette'=>$palette,'navigation'=>$navigation,'social'=>$social,
        ]);
    }

    protected function tearDown():void
    {
        foreach(self::SAMPLES as $choices) {
            $path=$this->folder($choices);
            if (is_link($path)) {
                @unlink($path);
                continue;
            }
            if (!is_dir($path))continue;
            foreach([
                'index.html','internal-review-dossier.json',
                'internal-review-draft.json','release-manifest.json',
            ] as $file) @unlink($path.'/'.$file);
            @rmdir($path);
        }
        putenv('SITES_F219_SEO_READBACK');
        putenv('SITES_F205_VISUAL_PACKAGE');
        parent::tearDown();
    }

    public function test_four_private_exports_are_inspected_without_any_mutation():void
    {
        $count=0;
        foreach(self::SAMPLES as $choices) {
            $path=$this->folder($choices);
            self::assertSame(0,$this->callFixture(
                'sites:export-visual-fixture',$choices
            ));
            $before=Verifier::verifyDirectory($path);
            $html=(string)file_get_contents($path.'/index.html');
            self::assertSame(0,$this->callFixture(
                'sites:inspect-seo-fixture',$choices
            ));
            $out=Artisan::output();
            self::assertStringContainsString('VERIFIED PRIVATE SEO',$out);
            self::assertStringContainsString('noindex and pending',$out);
            self::assertStringContainsString('Release: '.$before['releaseRef'],$out);
            self::assertStringContainsString('SHA-256: '.$before['artifactDigest'],$out);
            self::assertStringContainsString('SEO title characters: ',$out);
            self::assertStringContainsString('SEO description characters: ',$out);
            self::assertStringContainsString('SEO advisories: ',$out);
            self::assertStringContainsString('no public URL or index permission',$out);
            self::assertStringNotContainsString('https://',$out);

            $after=Verifier::verifyDirectory($path);
            self::assertSame($before,$after,
                'Read-only SEO command cannot change private package metadata');
            self::assertSame($html,
                (string)file_get_contents($path.'/index.html'));
            self::assertStringContainsString('noindex, nofollow',$html);
            self::assertStringNotContainsString('<form',$html);
            self::assertStringNotContainsString('<script',$html);
            self::assertSame($choices[5]==='on'?12:5,
                substr_count($html,'<meta '));
            ++$count;
        }
        self::assertSame(4,$count);
    }

    public function test_no_export_or_missing_lab_flag_fails_closed():void
    {
        $choice=self::SAMPLES[0];
        self::assertSame(1,$this->callFixture(
            'sites:inspect-seo-fixture',$choice
        ),'An inspector never auto-creates a missing export');
        putenv('SITES_F219_SEO_READBACK');
        try {
            self::assertSame(1,$this->callFixture(
                'sites:inspect-seo-fixture',$choice
            ));
        }finally{putenv('SITES_F219_SEO_READBACK=1');}

        foreach([
            ['landing_page','classic','services_process_faq','default','on','off'],
            ['blog','classic','services_process_faq','default','off','off'],
            ['onepage','unknown','services_process_faq','default','off','off'],
            ['onepage','classic','bogus','default','off','off'],
            ['onepage','classic','services_process_faq','https://evil.invalid','off','off'],
            ['onepage','classic','services_process_faq','default','off','image'],
        ] as $invalid) {
            self::assertSame(1,$this->callFixture(
                'sites:inspect-seo-fixture',$invalid
            ),'Out-of-catalog arguments must never become a private file path');
        }
    }

    public function test_tampering_and_symlinked_package_never_pass():void
    {
        $choice=self::SAMPLES[1];
        self::assertSame(0,$this->callFixture(
            'sites:export-visual-fixture',$choice
        ));
        $path=$this->folder($choice);
        $original=(string)file_get_contents($path.'/index.html');
        file_put_contents($path.'/index.html',
            str_replace('noindex, nofollow','index, follow',$original)
        );
        self::assertSame(1,$this->callFixture(
            'sites:inspect-seo-fixture',$choice
        ),'Tampered meta robots invalidates the source SHA and stops inspection');

        $other=self::SAMPLES[2];
        $marker=storage_path('app/private/f219-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        $link=$this->folder($other);
        self::assertTrue(symlink($marker,$link));
        try{
            self::assertSame(1,$this->callFixture(
                'sites:inspect-seo-fixture',$other
            ));
            self::assertSame('UNTOUCHED',
                (string)file_get_contents($marker));
        }finally{
            @unlink($link);
            @unlink($marker);
        }
    }
}
