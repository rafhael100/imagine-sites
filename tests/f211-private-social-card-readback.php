<?php

declare(strict_types=1);

/** F1-211: validate canonical social evidence after private bundle serialization. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$part))
        throw new RuntimeException('Unsafe synthetic class');
    require __DIR__.'/../src/'.str_replace('\\','/',$part).'.php';
});

use Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture as Design;
use Imagine\Sites\Production\SyntheticPrivateSocialCardReadbackV1 as Readback;

$count=0;
function check211(bool $ok,string $why):void{
    global $count;
    if(!$ok)throw new RuntimeException('F1-211 '.$why);
    ++$count;
}
function denied211(callable $action):bool{
    try{$action();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$sampleCount=0;
foreach(['onepage','landing_page'] as $product){
    foreach(['classic','modern','warm'] as $theme){
        foreach(['default','forest'] as $palette){
            foreach([false,true] as $social){
                $design=Design::build(
                    $copy,$product,$theme,'faq_process_services','off',$palette,$social
                );
                $files=$design->privateFiles();
                $report=Readback::inspect($files,$design->draft->brief);
                ++$sampleCount;
                check211($report->releaseRef===$design->draft->manifest->releaseRef &&
                    $report->artifactDigest===$design->draft->artifact->digest &&
                    $report->socialMetadataEnabled===$social &&
                    ($report->card!==null)===$social,
                    'verified four-file package must match exact social mode and release');
                check211(!$report->canPublish() &&
                    !$report->canMarkClientAccepted() &&
                    !$report->canSendToCrm() &&
                    !$report->canFetchRemoteMedia() &&
                    !$report->canSharePublicly(),
                    'stored metadata never becomes a real operational permission');
                if($social){
                    check211($report->card->artifactDigest===$report->artifactDigest &&
                        $report->card->title===$design->draft->brief->headline.
                            ' | '.$design->draft->brief->brand &&
                        $report->card->description===$design->draft->brief->description,
                        'stored card exactly matches the renderer and text brief');
                }else{
                    check211($report->card===null &&
                        !str_contains($files['index.html'],'og:title') &&
                        !str_contains($files['index.html'],'twitter:card'),
                        'disabled variant cannot fabricate stored social preview');
                }

                $tampered=$files;
                $tampered['index.html']=str_replace(
                    '<meta property="og:title"',
                    '<meta property="og:image"',
                    $tampered['index.html']
                );
                if(!$social){
                    $tampered['index.html'].='<!-- unauthorized edit -->';
                }
                check211(denied211(static fn()=>Readback::inspect(
                    $tampered,$design->draft->brief
                )),'mutated HTML invalidates canonical manifest and social evidence');

                $cross=Design::build(
                    $copy,$product,$theme,'services_process_faq','off',$palette,$social
                );
                check211(denied211(static fn()=>Readback::inspect(
                    $files,$cross->draft->brief
                )),'another intended layout cannot claim same stored HTML bytes');

                $bad=$files;
                $bad['index.htm']=$bad['index.html'];
                check211(denied211(static fn()=>Readback::inspect(
                    $bad,$design->draft->brief
                )),'unknown extra file cannot masquerade as four-file package');

                $bad=$files;
                $bad['release-manifest.json']=str_replace(
                    $design->draft->artifact->digest,
                    str_repeat('0',64),
                    $bad['release-manifest.json']
                );
                check211(denied211(static fn()=>Readback::inspect(
                    $bad,$design->draft->brief
                )),'forged manifest digest cannot pass readback');
            }
        }
    }
}
check211($sampleCount===24,'24 private packages across products/theme/palette/social');
echo "$count F1-211 four-file social-card readback assertions passed. ".
    "No credentials, real client, network requests, CRM or release.\n";
