<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verifier;
use Tests\TestCase;

/** F1-209: social-on + entire visual choice, still no client or publisher. */
final class SyntheticCompositeSocialPrivateBundleTest extends TestCase
{
    private const EXAMPLES=[
        ['onepage','classic','services_process_faq','default','off','on'],
        ['onepage','modern','faq_process_services','forest','on','on'],
        ['landing_page','warm','process_faq_services','plum','off','on'],
        ['landing_page','classic','faq_services_process','ocean','off','on'],
    ];

    protected function setUp():void
    {
        putenv('SITES_F205_VISUAL_PACKAGE=1');
        parent::setUp();
        $root=storage_path('app/private');
        if (is_link($root) ||
            (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Unsafe private synthetic store');
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
            if(!is_dir($path))continue;
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
        string $palette,string $navigation,string $social
    ):string {
        return storage_path('app/private/sites-f1-205-'.$product.'-'.$preset.
            '-'.$order.'-'.$palette.'-'.$navigation.
            ($social==='on'?'-social-on':''));
    }

    private function export(
        string $product,string $preset,string $order,
        string $palette,string $navigation,string $social
    ):int {
        return Artisan::call('sites:export-visual-fixture',[
            'product'=>$product,'preset'=>$preset,'order'=>$order,
            'palette'=>$palette,'navigation'=>$navigation,'social'=>$social,
        ]);
    }

    public function test_four_social_on_combinations_write_private_unapproved_files():void
    {
        $sha=[];
        foreach(self::EXAMPLES as $selection) {
            self::assertSame(0,$this->export(...$selection));
            $directory=$this->location(...$selection);
            $proof=Verifier::verifyDirectory($directory);
            self::assertTrue($proof['verifiedPrivateLabFiles']);
            self::assertSame('tenant-f205',$proof['tenantRef']);
            self::assertSame('site-f205',$proof['siteRef']);
            self::assertSame('release-f205-'.implode('-',array_slice($selection,0,5)).
                '-social-on',$proof['releaseRef']);
            self::assertSame('pending',$proof['reviewerDecisionStatus']);
            self::assertFalse($proof['publishable']);
            self::assertFalse($proof['officialClientAcceptance']);
            self::assertTrue($proof['externalPortalAuthorityRequired']);

            $html=(string)file_get_contents($directory.'/index.html');
            self::assertSame($proof['artifactDigest'],hash('sha256',$html));
            self::assertSame(12,substr_count($html,'<meta '));
            self::assertStringContainsString(
                '<meta property="og:type" content="website">',$html
            );
            self::assertStringContainsString(
                '<meta name="twitter:card" content="summary">',$html
            );
            self::assertStringNotContainsString('og:url',$html);
            self::assertStringNotContainsString('og:image',$html);
            self::assertStringNotContainsString('<script',$html);
            self::assertStringNotContainsString('<form',$html);
            self::assertStringContainsString('noindex, nofollow',$html);
            $sha[]=$proof['artifactDigest'];
        }
        self::assertCount(count(self::EXAMPLES),array_unique($sha));
    }

    public function test_invalid_modes_repeat_and_symlink_are_rejected():void
    {
        $valid=self::EXAMPLES[0];
        $bad=$valid;
        $bad[5]='maybe';
        self::assertSame(1,$this->export(...$bad));
        $bad=$valid;
        $bad[0]='blog';
        self::assertSame(1,$this->export(...$bad));
        $bad=$valid;
        $bad[3]='https://example.test';
        self::assertSame(1,$this->export(...$bad));
        putenv('SITES_F205_VISUAL_PACKAGE');
        try{self::assertSame(1,$this->export(...$valid));}
        finally{putenv('SITES_F205_VISUAL_PACKAGE=1');}
        self::assertSame(0,$this->export(...$valid));
        self::assertSame(1,$this->export(...$valid));
        self::assertTrue(
            Verifier::verifyDirectory($this->location(...$valid))['verifiedPrivateLabFiles']
        );

        $different=self::EXAMPLES[1];
        $path=$this->location(...$different);
        $marker=storage_path('app/private/f209-protected.marker');
        file_put_contents($marker,'UNTOUCHED');
        self::assertTrue(symlink($marker,$path));
        try{
            self::assertSame(1,$this->export(...$different));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        }finally{
            @unlink($path);
            @unlink($marker);
        }
        self::assertSame(0,$this->export(...$different));
        file_put_contents($path.'/index.html','invalidated');
        $rejected=false;
        try{Verifier::verifyDirectory($path);}catch(\DomainException){$rejected=true;}
        self::assertTrue($rejected);
    }
}
