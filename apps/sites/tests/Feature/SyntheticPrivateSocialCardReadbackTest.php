<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-211: read existing private social HTML only after independent readback. */
final class SyntheticPrivateSocialCardReadbackTest extends TestCase
{
    private const ORDER='services_process_faq';

    protected function setUp():void
    {
        putenv('SITES_F205_VISUAL_PACKAGE=1');
        putenv('SITES_F211_SOCIAL_READBACK=1');
        parent::setUp();
        $root=storage_path('app/private');
        if(is_link($root) ||
            (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Unsafe synthetic private storage root');
        }
    }

    private function folder(string $product,string $preset,string $social):string
    {
        return storage_path('app/private/sites-f1-205-'.$product.'-'.$preset.'-'.
            self::ORDER.'-default-off'.($social==='on'?'-social-on':''));
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','warm'] as $preset) {
                foreach(['on','off'] as $mode) {
                    $path=$this->folder($product,$preset,$mode);
                    if(is_link($path)) {@unlink($path);continue;}
                    if(!is_dir($path))continue;
                    foreach([
                        'index.html','internal-review-dossier.json',
                        'internal-review-draft.json','release-manifest.json',
                    ] as $name)@unlink($path.'/'.$name);
                    @rmdir($path);
                }
            }
        }
        putenv('SITES_F205_VISUAL_PACKAGE');
        putenv('SITES_F211_SOCIAL_READBACK');
        parent::tearDown();
    }

    private function invoke(string $command,string $product,
        string $preset,string $social):int
    {
        return Artisan::call($command,[
            'product'=>$product,'preset'=>$preset,
            'order'=>self::ORDER,'palette'=>'default',
            'navigation'=>'off','social'=>$social,
        ]);
    }

    public function test_existing_private_packages_have_pinned_social_card_evidence():void
    {
        $samples=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','warm'] as $preset) {
                foreach(['off','on'] as $social) {
                    self::assertSame(0,$this->invoke(
                        'sites:export-visual-fixture',$product,$preset,$social
                    ));
                    $path=$this->folder($product,$preset,$social);
                    self::assertDirectoryExists($path);
                    self::assertSame(0,$this->invoke(
                        'sites:inspect-social-fixture',$product,$preset,$social
                    ));
                    $output=Artisan::output();
                    self::assertStringContainsString('VERIFIED PRIVATE TEXT-ONLY',$output);
                    self::assertStringContainsString('Social metadata: '.$social,$output);
                    self::assertStringContainsString('SHA-256: ',$output);
                    self::assertStringContainsString('not published',$output);

                    $html=(string)file_get_contents($path.'/index.html');
                    self::assertStringContainsString('noindex, nofollow',$html);
                    self::assertSame($social==='on'?12:5,substr_count($html,'<meta '));
                    self::assertStringNotContainsString('og:image',$html);
                    self::assertStringNotContainsString('og:url',$html);
                    self::assertStringNotContainsString('<form',$html);
                    self::assertStringNotContainsString('<script',$html);
                    ++$samples;
                }
            }
        }
        self::assertSame(8,$samples);
    }

    public function test_altered_or_symlinked_package_cannot_pass_readback():void
    {
        $product='onepage';$preset='classic';$social='on';
        self::assertSame(0,$this->invoke(
            'sites:export-visual-fixture',$product,$preset,$social
        ));
        $folder=$this->folder($product,$preset,$social);
        file_put_contents($folder.'/index.html','<!-- overwritten -->');
        self::assertSame(1,$this->invoke(
            'sites:inspect-social-fixture',$product,$preset,$social
        ),'Tampering destroys independent manifest readback');

        $link=$this->folder('landing_page','warm','on');
        $marker=storage_path('app/private/f211-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        self::assertTrue(symlink($marker,$link));
        try {
            self::assertSame(1,$this->invoke(
                'sites:inspect-social-fixture','landing_page','warm','on'
            ));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        }finally {
            @unlink($link);
            @unlink($marker);
        }
    }

    public function test_denies_missing_package_or_disabled_lab_flag():void
    {
        self::assertSame(1,$this->invoke(
            'sites:inspect-social-fixture','onepage','classic','off'
        ));
        putenv('SITES_F211_SOCIAL_READBACK');
        try {
            self::assertSame(1,$this->invoke(
                'sites:inspect-social-fixture','onepage','classic','on'
            ));
        }finally {putenv('SITES_F211_SOCIAL_READBACK=1');}
        self::assertSame(1,Artisan::call('sites:inspect-social-fixture',[
            'product'=>'landing_page','preset'=>'classic',
            'order'=>self::ORDER,'palette'=>'default',
            'navigation'=>'on','social'=>'on',
        ]),'Invalid navigation cannot select a different tenant or path');
        self::assertSame(1,Artisan::call('sites:inspect-social-fixture',[
            'product'=>'onepage','preset'=>'classic',
            'order'=>self::ORDER,'palette'=>'default',
            'navigation'=>'off','social'=>'remote-image',
        ]));
    }
}
