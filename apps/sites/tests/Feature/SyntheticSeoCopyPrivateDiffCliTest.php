<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-227: exact read-only head diff between two private review packages. */
final class SyntheticSeoCopyPrivateDiffCliTest extends TestCase
{
    private const FLAGS=['SITES_F223_SEO_EXPORT','SITES_F227_SEO_DIFF'];

    private function location(string $p,string $preset,string $seo,string $social):string
    {
        return storage_path('app/private/sites-f1-223-'.$p.'-'.$preset.'-'.$seo.'-'.$social);
    }

    private function export(string $p,string $preset,string $seo,string $social):int
    {
        return Artisan::call('sites:export-seo-copy-fixture',[
            'product'=>$p,'preset'=>$preset,'seo'=>$seo,'social'=>$social
        ]);
    }

    private function compare(string $p,string $preset,string $social):int
    {
        return Artisan::call('sites:diff-seo-copy-fixture',[
            'product'=>$p,'preset'=>$preset,'social'=>$social
        ]);
    }

    protected function setUp():void
    {
        foreach(self::FLAGS as $flag)putenv($flag.'=1');
        parent::setUp();
        $root=storage_path('app/private');
        if (is_link($root) || (!is_dir($root) &&
            !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Private comparison CI directory unsafe.');
        }
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $p) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['default','custom'] as $seo) {
                    foreach(['off','on'] as $social) {
                        $path=$this->location($p,$preset,$seo,$social);
                        if (is_link($path)) {
                            @unlink($path);
                            continue;
                        }
                        if (!is_dir($path))continue;
                        foreach([
                            'index.html','internal-review-dossier.json',
                            'internal-review-draft.json','release-manifest.json'
                        ] as $file) @unlink($path.'/'.$file);
                        @rmdir($path);
                    }
                }
            }
        }
        foreach(self::FLAGS as $flag)putenv($flag);
        parent::tearDown();
    }

    public function test_twelve_compared_pairs_never_rewrite_private_files():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $p) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['off','on'] as $social) {
                    $snapshot=[];
                    foreach(['default','custom'] as $seo) {
                        self::assertSame(0,$this->export($p,$preset,$seo,$social));
                        $path=$this->location($p,$preset,$seo,$social);
                        $snapshot[$seo]=[];
                        foreach([
                            'index.html','internal-review-dossier.json',
                            'internal-review-draft.json','release-manifest.json'
                        ] as $file) {
                            $snapshot[$seo][$file]=hash_file('sha256',$path.'/'.$file);
                        }
                    }

                    self::assertSame(0,$this->compare($p,$preset,$social));
                    $first=Artisan::output();
                    self::assertSame(0,$this->compare($p,$preset,$social));
                    self::assertSame($first,Artisan::output());
                    self::assertStringContainsString(
                        'SEO COPY DIFFERENCE VERIFIED',$first
                    );
                    self::assertStringContainsString(
                        'head only; pending, noindex',$first
                    );
                    self::assertStringContainsString(
                        'Changes: seoTitle,seoDescription',$first
                    );
                    self::assertStringContainsString(
                        'Social metadata: '.$social,$first
                    );
                    self::assertMatchesRegularExpression(
                        '/Default SHA-256: [a-f0-9]{64}/',$first
                    );
                    self::assertMatchesRegularExpression(
                        '/Custom SHA-256: [a-f0-9]{64}/',$first
                    );
                    self::assertStringNotContainsString(
                        'Serviços de comunicação e tecnologia',$first
                    );
                    self::assertStringNotContainsString('https://',$first);
                    foreach($snapshot as $seo=>$files) {
                        $path=$this->location($p,$preset,$seo,$social);
                        foreach($files as $name=>$hash) {
                            self::assertSame($hash,hash_file('sha256',$path.'/'.$name),
                                'Auditing does not create or rewrite files');
                        }
                    }
                    ++$count;
                }
            }
        }
        self::assertSame(12,$count);
    }

    public function test_missing_half_and_disabled_gate_are_denied():void
    {
        self::assertSame(1,$this->compare('onepage','classic','off'));
        self::assertDirectoryDoesNotExist(
            $this->location('onepage','classic','default','off')
        );
        self::assertSame(0,$this->export('onepage','classic','default','off'));
        self::assertSame(1,$this->compare('onepage','classic','off'));
        self::assertSame(0,$this->export('onepage','classic','custom','off'));
        putenv('SITES_F227_SEO_DIFF');
        try {
            self::assertSame(1,$this->compare('onepage','classic','off'));
        } finally {putenv('SITES_F227_SEO_DIFF=1');}
        self::assertSame(0,$this->compare('onepage','classic','off'));
    }

    public function test_invalid_option_tampered_file_and_symlink_are_denied():void
    {
        foreach([
            ['unknown','classic','off'],
            ['onepage','unknown','off'],
            ['onepage','classic','https://'],
            ['../etc','classic','on']
        ] as $params)self::assertSame(1,$this->compare(...$params));

        self::assertSame(0,$this->export('landing_page','warm','default','on'));
        self::assertSame(0,$this->export('landing_page','warm','custom','on'));
        $file=$this->location('landing_page','warm','custom','on').'/index.html';
        $html=(string)file_get_contents($file);
        file_put_contents($file,str_replace(
            'noindex, nofollow','index, follow',$html
        ));
        self::assertSame(1,$this->compare('landing_page','warm','on'));

        $marker=storage_path('app/private/f227-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        $fake=$this->location('onepage','warm','custom','off');
        self::assertTrue(symlink($marker,$fake));
        try {
            self::assertSame(1,$this->compare('onepage','warm','off'));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        }finally {
            @unlink($fake);
            @unlink($marker);
        }
    }
}
