<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticPrivateDraftVerifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * F1-184: only fixed fictional variants can become a four-file PRIVATE bundle.
 * Neither a file digest nor a synthetic role grants client approval.
 */
final class SyntheticCombinedLayoutPrivateBundleTest extends TestCase
{
    private const ORDERS=[
        'services_process_faq'=>['highlights','process','faq'],
        'services_faq_process'=>['highlights','faq','process'],
        'process_services_faq'=>['process','highlights','faq'],
        'process_faq_services'=>['process','faq','highlights'],
        'faq_services_process'=>['faq','highlights','process'],
        'faq_process_services'=>['faq','process','highlights'],
    ];

    private function root():string
    {
        return storage_path('app/private');
    }

    private function location(string $product,string $preset,
        string $order,string $navigation):string
    {
        return $this->root().'/sites-f1-184-'.$product.'-'.$preset.'-'.$order.'-'.$navigation;
    }

    protected function setUp():void
    {
        putenv('SITES_F184_LAYOUT_PACKAGE=1');
        parent::setUp();
        $root=$this->root();
        if (is_link($root) ||
            (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Private synthetic storage root denied.');
        }
    }

    protected function tearDown():void
    {
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(array_keys(self::ORDERS) as $order) {
                    foreach(['on','off'] as $mode) {
                        $path=$this->location($product,$preset,$order,$mode);
                        if (is_link($path)) {
                            @unlink($path);
                            continue;
                        }
                        if (!is_dir($path))continue;
                        foreach([
                            'index.html','internal-review-dossier.json',
                            'internal-review-draft.json','release-manifest.json',
                        ] as $filename)@unlink($path.'/'.$filename);
                        @rmdir($path);
                    }
                }
            }
        }
        putenv('SITES_F184_LAYOUT_PACKAGE');
        parent::tearDown();
    }

    private function invoke(string $product,string $preset,
        string $order,string $mode):int
    {
        return Artisan::call('sites:export-layout-review-fixture',[
            'product'=>$product,'preset'=>$preset,
            'order'=>$order,'navigation'=>$mode,
        ]);
    }

    public function test_all_54_fixed_layouts_are_unique_verified_and_pending():void
    {
        $unique=[];
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $preset) {
                foreach(self::ORDERS as $orderCode=>$order) {
                    foreach($product==='onepage'?['off','on']:['off'] as $mode) {
                        self::assertSame(0,$this->invoke(
                            $product,$preset,$orderCode,$mode
                        ),'Fixed fictional layout must produce a private draft');
                        $destination=$this->location($product,$preset,$orderCode,$mode);
                        $proof=SyntheticPrivateDraftVerifier::verifyDirectory($destination);
                        self::assertSame('tenant-f184',$proof['tenantRef']);
                        self::assertSame('site-f184',$proof['siteRef']);
                        self::assertSame('release-f184-'.$product.'-'.$preset.'-'.$orderCode.'-'.$mode,
                            $proof['releaseRef']);
                        self::assertSame('pending',$proof['reviewerDecisionStatus']);
                        self::assertFalse($proof['publishable']);
                        self::assertFalse($proof['officialClientAcceptance']);
                        self::assertTrue($proof['verifiedPrivateLabFiles']);

                        $html=(string)file_get_contents($destination.'/index.html');
                        self::assertSame(hash('sha256',$html),$proof['artifactDigest']);
                        self::assertStringContainsString('noindex, nofollow',$html);
                        foreach(['faq','process','highlights'] as $section) {
                            self::assertSame(1,substr_count(
                                $html,'<section class="'.$section.'"'
                            ));
                        }
                        self::assertStringNotContainsString('<form',$html);
                        self::assertStringNotContainsString('<script',$html);
                        $pos=[];
                        foreach($order as $section) {
                            $needle=match($section) {
                                'highlights'=>'id="solucoes"',
                                'process'=>'id="etapas"',
                                'faq'=>'id="perguntas"',
                            };
                            $idx=strpos($html,$needle);
                            self::assertNotFalse($idx);
                            $pos[]=$idx;
                        }
                        self::assertTrue($pos[0]<$pos[1] && $pos[1]<$pos[2]);
                        foreach(['#solucoes','#etapas','#perguntas'] as $fragment) {
                            if ($mode==='on') {
                                self::assertStringContainsString('<a href="'.$fragment.'">',$html);
                            }else{
                                self::assertStringNotContainsString('<a href="'.$fragment.'">',$html);
                            }
                        }
                        $summary=json_decode(
                            (string)file_get_contents($destination.'/internal-review-draft.json'),
                            true,16,JSON_THROW_ON_ERROR
                        );
                        $dossier=json_decode(
                            (string)file_get_contents($destination.'/internal-review-dossier.json'),
                            true,16,JSON_THROW_ON_ERROR
                        );
                        self::assertSame('internal_review',$summary['workflowState']);
                        self::assertTrue($summary['internalReviewRequired']);
                        self::assertFalse($summary['publishable']);
                        self::assertSame('pending',$dossier['reviewerDecisionStatus']);
                        self::assertTrue($dossier['portalAuthorityRequired']);
                        self::assertSame($proof['dossierDigest'],
                            hash('sha256',(string)file_get_contents(
                                $destination.'/internal-review-dossier.json'
                            )));
                        $unique[]=$proof['artifactDigest'];
                    }
                }
            }
        }
        self::assertCount(54,array_unique($unique),
            'Each product, preset, layout and navigation combination has its own SHA');
    }

    public function test_invalid_arguments_and_unset_flag_never_create_output():void
    {
        foreach([
            ['landing_page','classic','services_process_faq','on'],
            ['other','classic','services_process_faq','off'],
            ['onepage','custom','services_process_faq','on'],
            ['onepage','classic','faq_unknown','off'],
            ['onepage','classic','services_process_faq','maybe'],
            ['onepage','classic','../etc/passwd','off'],
        ] as [$product,$preset,$order,$mode]) {
            self::assertSame(1,$this->invoke($product,$preset,$order,$mode));
        }
        putenv('SITES_F184_LAYOUT_PACKAGE');
        try{
            self::assertSame(1,$this->invoke(
                'onepage','classic','services_process_faq','off'
            ));
        }finally{putenv('SITES_F184_LAYOUT_PACKAGE=1');}
        self::assertDirectoryDoesNotExist($this->location(
            'onepage','classic','services_process_faq','off'
        ));
    }

    public function test_replay_symlink_and_tampering_are_denied():void
    {
        $args=['onepage','classic','faq_process_services','on'];
        self::assertSame(0,$this->invoke(...$args));
        $destination=$this->location(...$args);
        $original=(string)file_get_contents($destination.'/index.html');
        self::assertSame(1,$this->invoke(...$args));
        self::assertSame($original,
            (string)file_get_contents($destination.'/index.html'));
        self::assertTrue(
            SyntheticPrivateDraftVerifier::verifyDirectory($destination)['verifiedPrivateLabFiles']
        );
        file_put_contents($destination.'/index.html','changed externally');
        $rejected=false;
        try {SyntheticPrivateDraftVerifier::verifyDirectory($destination);}
        catch(\DomainException){$rejected=true;}
        self::assertTrue($rejected,'Tampered HTML cannot verify as a private review draft');

        $marker=$this->root().'/f184-protected.marker';
        file_put_contents($marker,'UNTOUCHED');
        $target=$this->location('onepage','warm','services_faq_process','on');
        self::assertTrue(symlink($marker,$target));
        try {
            self::assertSame(1,$this->invoke(
                'onepage','warm','services_faq_process','on'
            ));
            self::assertSame('UNTOUCHED',(string)file_get_contents($marker));
        } finally {
            @unlink($target);
            @unlink($marker);
        }
    }
}
