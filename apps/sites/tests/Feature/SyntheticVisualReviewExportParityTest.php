<?php

declare(strict_types=1);

namespace Tests\Feature;

use Imagine\Sites\Production\SyntheticPrivateDraftVerifier as Verifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * F1-213: the private saved HTML must be byte-identical to the reviewer
 * HTML for the SAME fixed design selection. No real site is delivered.
 */
final class SyntheticVisualReviewExportParityTest extends TestCase
{
    private const PATH='/_lab/sites/v1/composite-social';
    private const FLAGS=[
        'SITES_SYNTHETIC_HTTP','SITES_SYNTHETIC_REVIEW_UI',
        'SITES_SYNTHETIC_RICH_REVIEW','SITES_F212_COMPOSITE_SOCIAL_REVIEW',
        'SITES_F205_VISUAL_PACKAGE','SITES_F211_SOCIAL_READBACK',
    ];
    /** @var list<string> */
    private array $created=[];

    protected function setUp():void
    {
        foreach(self::FLAGS as $key)putenv($key.'=1');
        parent::setUp();
        $root=storage_path('app/private');
        if(is_link($root) ||
            (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) ||
            !is_dir($root) || !chmod($root,0700)) {
            throw new \RuntimeException('Private CI review root denied.');
        }
    }

    protected function tearDown():void
    {
        foreach($this->created as $path) {
            if(is_link($path)) {@unlink($path);continue;}
            if(!is_dir($path))continue;
            foreach([
                'index.html','internal-review-dossier.json',
                'internal-review-draft.json','release-manifest.json',
            ] as $name)@unlink($path.'/'.$name);
            @rmdir($path);
        }
        foreach(self::FLAGS as $key)putenv($key);
        parent::tearDown();
    }

    /** @return array<string,string> */
    private function selection(string $product,string $theme,
        string $order,string $nav,string $palette,string $social):array
    {
        return [
            'product'=>$product,'preset'=>$theme,'order'=>$order,
            'navigation'=>$nav,'palette'=>$palette,'social'=>$social,
        ];
    }
    private function folder(array $args):string
    {
        return storage_path('app/private/sites-f1-205-').
            $args['product'].'-'.$args['preset'].'-'.$args['order'].'-'.
            $args['palette'].'-'.$args['navigation'].
            ($args['social']==='on'?'-social-on':'');
    }
    private function url(array $a,string $device='desktop'):string
    {
        return self::PATH.'?product='.$a['product'].'&device='.$device.
            '&preset='.$a['preset'].'&order='.$a['order'].
            '&nav='.$a['navigation'].'&palette='.$a['palette'].
            '&social='.$a['social'];
    }

    public function test_twelve_saved_packages_exactly_match_review_iframe_and_digest():void
    {
        $count=0;
        foreach(['onepage','landing_page'] as $product) {
            foreach(['classic','modern','warm'] as $theme) {
                foreach(['off','on'] as $social) {
                    $order=$theme==='warm'?'faq_process_services':'services_process_faq';
                    $palette=$theme==='modern'?'forest':'default';
                    $a=$this->selection($product,$theme,$order,'off',$palette,$social);
                    $route=$this->get($this->url($a,'desktop'))->assertOk();
                    $outer=(string)$route->getContent();
                    self::assertSame(1,preg_match(
                        '/id="artifact-digest">([a-f0-9]{64})<\/code>/',
                        $outer,$digest
                    ));
                    self::assertSame(1,preg_match(
                        '/\bsrcdoc="([^"]+)"/s',$outer,$match
                    ));
                    $html=html_entity_decode(
                        $match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'
                    );
                    self::assertSame(hash('sha256',$html),$digest[1]);

                    self::assertSame(0,Artisan::call(
                        'sites:export-visual-fixture',$a
                    ));
                    $folder=$this->folder($a);
                    $this->created[]=$folder;
                    self::assertDirectoryExists($folder);
                    $proof=Verifier::verifyDirectory($folder);
                    $persisted=(string)file_get_contents($folder.'/index.html');
                    self::assertSame($html,$persisted,
                        'Private persisted HTML must equal the exact bytes shown in iframe');
                    self::assertSame($digest[1],$proof['artifactDigest']);
                    self::assertFalse($proof['publishable']);
                    self::assertFalse($proof['officialClientAcceptance']);
                    self::assertSame('pending',$proof['reviewerDecisionStatus']);
                    self::assertSame(0,Artisan::call(
                        'sites:inspect-social-fixture',$a
                    ));
                    self::assertStringContainsString(
                        'Social metadata: '.$social,Artisan::output()
                    );
                    self::assertSame($social==='on'?12:5,
                        substr_count($persisted,'<meta '));
                    self::assertSame(1,Artisan::call(
                        'sites:export-visual-fixture',$a
                    ),'Same private release cannot overwrite an existing review');
                    self::assertSame($persisted,
                        (string)file_get_contents($folder.'/index.html'));
                    ++$count;
                }
            }
        }
        self::assertSame(12,$count);
    }

    public function test_onepage_menu_on_stays_identical_between_review_and_export():void
    {
        $a=$this->selection(
            'onepage','warm','faq_process_services','on','plum','on'
        );
        $outer=(string)$this->get($this->url($a))->assertOk()->getContent();
        self::assertSame(1,preg_match('/\bsrcdoc="([^"]+)"/s',$outer,$m));
        $html=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        self::assertSame(0,Artisan::call('sites:export-visual-fixture',$a));
        $folder=$this->folder($a);
        $this->created[]=$folder;
        self::assertSame($html,(string)file_get_contents($folder.'/index.html'));
        self::assertStringContainsString('<a href="#perguntas">FAQ</a>',$html);
        self::assertStringContainsString('og:title',$html);
        self::assertSame(0,Artisan::call('sites:inspect-social-fixture',$a));
    }

    public function test_tampered_package_is_rejected_without_changing_review_bytes():void
    {
        $a=$this->selection(
            'onepage','classic','services_process_faq','off','default','on'
        );
        $original=(string)$this->get($this->url($a))->assertOk()->getContent();
        self::assertSame(0,Artisan::call('sites:export-visual-fixture',$a));
        $folder=$this->folder($a);
        $this->created[]=$folder;
        file_put_contents($folder.'/index.html','tampered after export');
        self::assertSame(1,Artisan::call('sites:inspect-social-fixture',$a));
        $new=(string)$this->get($this->url($a))->assertOk()->getContent();
        self::assertSame($original,$new,
            'Private reviewer never silently adopts changed stored bytes');
    }
}
