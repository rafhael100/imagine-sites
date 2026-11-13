<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** F1-225: read-only auditing of fixed existing SEO-copy review packages. */
final class SyntheticSeoCopyPrivateAuditTest extends TestCase
{
    private const FLAGS=[
        'SITES_F223_SEO_EXPORT','SITES_F225_SEO_AUDIT',
    ];
    private function location(
        string $product,string $preset,string $seo,string $social
    ):string {
        return storage_path('app/private/sites-f1-223-'.$product.'-'.$preset.
            '-'.$seo.'-'.$social);
    }
    private function cli(string $verb,array $args):int
    {
        return Artisan::call('sites:'.$verb.'-seo-copy-fixture',[
            'product'=>$args[0],'preset'=>$args[1],
            'seo'=>$args[2],'social'=>$args[3],
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
            throw new \RuntimeException('Unsafe SEO audit root.');
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

    public function test_all_24_seo_packages_audit_without_changing_any_file():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(['off','on'] as $social) {
                    foreach(['default','custom'] as $seo) {
                        $args=[$product,$preset,$seo,$social];
                        self::assertSame(0,$this->cli('export',$args));
                        $dir=$this->location(...$args);
                        $before=[];
                        foreach([
                            'index.html','internal-review-dossier.json',
                            'internal-review-draft.json','release-manifest.json'
                        ] as $name) {
                            $before[$name]=hash_file('sha256',$dir.'/'.$name);
                        }
                        self::assertSame(0,$this->cli('audit',$args));
                        $firstOutput=Artisan::output();
                        self::assertSame(0,$this->cli('audit',$args));
                        self::assertSame($firstOutput,Artisan::output());
                        self::assertStringContainsString(
                            'PRIVATE SEO COPY VERIFIED',$firstOutput
                        );
                        self::assertStringContainsString(
                            'noindex, no client approval',$firstOutput
                        );
                        self::assertStringContainsString(
                            'release-f223-'.$product.'-'.$preset.'-'.$seo.'-'.$social,
                            $firstOutput
                        );
                        self::assertMatchesRegularExpression(
                            '/SHA-256: [a-f0-9]{64}/',$firstOutput
                        );
                        self::assertStringContainsString(
                            'SEO title characters: ',$firstOutput
                        );
                        self::assertStringNotContainsString(
                            'Serviços de comunicação e tecnologia',$firstOutput,
                            'Audit must not dump the fictional title or customer text'
                        );
                        self::assertStringNotContainsString(
                            'https://',$firstOutput
                        );
                        foreach($before as $name=>$hash) {
                            self::assertSame($hash,hash_file(
                                'sha256',$dir.'/'.$name
                            ),'Read-only audit cannot rewrite private package bytes');
                        }
                        ++$count;
                    }
                }
            }
        }
        self::assertSame(24,$count);
    }

    public function test_audit_requires_existing_package_and_correct_flags():void
    {
        $args=['onepage','classic','custom','on'];
        self::assertSame(1,$this->cli('audit',$args));
        self::assertDirectoryDoesNotExist($this->location(...$args));
        self::assertSame(0,$this->cli('export',$args));
        putenv('SITES_F225_SEO_AUDIT');
        try {
            self::assertSame(1,$this->cli('audit',$args));
        }finally {putenv('SITES_F225_SEO_AUDIT=1');}
        self::assertSame(0,$this->cli('audit',$args));
    }

    public function test_invalid_selectors_symlink_and_modified_head_fail_closed():void
    {
        foreach([
            ['blog','classic','default','off'],
            ['onepage','unknown','custom','off'],
            ['onepage','classic','manipulated','on'],
            ['landing_page','classic','custom','public'],
            ['../path','classic','custom','off'],
        ] as $input)self::assertSame(1,$this->cli('audit',$input));

        $args=['onepage','warm','custom','on'];
        self::assertSame(0,$this->cli('export',$args));
        $dir=$this->location(...$args);
        $path=$dir.'/index.html';
        $html=(string)file_get_contents($path);
        file_put_contents($path,str_replace(
            'noindex, nofollow','index, follow',$html
        ));
        self::assertSame(1,$this->cli('audit',$args));

        $marker=storage_path('app/private/f225-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        $alias=$this->location('landing_page','warm','default','on');
        self::assertTrue(symlink($marker,$alias));
        try {
            self::assertSame(1,$this->cli('audit',[
                'landing_page','warm','default','on'
            ]));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        }finally {
            @unlink($alias);
            @unlink($marker);
        }
    }
}
