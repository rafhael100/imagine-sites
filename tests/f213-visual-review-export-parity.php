<?php

declare(strict_types=1);

/** F1-213: exact parity checks for stored composite visual designs. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unexpected fixture class');
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});

use Imagine\Sites\Production\SyntheticCompositeVisualPrivateFixture as Design;
use Imagine\Sites\Production\SyntheticVisualReviewExportParityV1 as Parity;

$checks=0;
function check213(bool $valid,string $label):void{
    global $checks;
    if(!$valid)throw new RuntimeException('F1-213 '.$label);
    ++$checks;
}
function deny213(callable $operation):bool{
    try{$operation();}catch(DomainException){return true;}
    return false;
}
$copy=json_decode(
    (string)file_get_contents(__DIR__.'/../fixtures/sites/f1-170-process-copy.synthetic.json'),
    true,16,JSON_THROW_ON_ERROR
);
$variants=0;
foreach(['onepage','landing_page'] as $product){
    foreach(['classic','modern','warm'] as $preset){
        foreach(['default','forest'] as $palette){
            foreach([false,true] as $social){
                $design=Design::build(
                    $copy,$product,$preset,'faq_process_services','off',
                    $palette,$social
                );
                $files=$design->privateFiles();
                $proof=Parity::inspect($design,$files);
                ++$variants;
                check213($proof->releaseRef===$design->draft->manifest->releaseRef &&
                    $proof->artifactDigest===$design->draft->artifact->digest &&
                    $proof->socialEnabled===$social &&
                    ($proof->socialCard!==null)===$social,
                    'private export identity must be pinned to its reviewer choice');
                check213(!$proof->canPublish() && !$proof->canAcceptForClient() &&
                    !$proof->canWriteCrm() && !$proof->canSkipReview(),
                    'even identical reviewed/exported bytes cannot authorize client workflow');
                $opposite=Design::build(
                    $copy,$product,$preset,'faq_process_services','off',
                    $palette,!$social
                );
                check213(deny213(static fn()=>Parity::inspect(
                    $opposite,$files
                )),'social toggle requires a new artifact and cannot reuse old files');
                $otherOrder=Design::build(
                    $copy,$product,$preset,'services_process_faq','off',
                    $palette,$social
                );
                check213(deny213(static fn()=>Parity::inspect(
                    $otherOrder,$files
                )),'reordered HTML is not interchangeable with prior export');
                $tampered=$files;
                $tampered['index.html'].='<!-- impossible later edit -->';
                check213(deny213(static fn()=>Parity::inspect(
                    $design,$tampered
                )),'changed HTML cannot pass SHA-256 readback');
                $tampered=$files;
                $tampered['internal-review-draft.json']=str_replace(
                    '"publishable": false','"publishable": true',
                    $tampered['internal-review-draft.json']
                );
                check213(deny213(static fn()=>Parity::inspect(
                    $design,$tampered
                )),'forged publishable true cannot pass review parity');
            }
        }
    }
}
check213($variants===24,'24 synthetic designs including social variants');
echo "$checks F1-213 exact reviewer/export parity checks passed. ".
    "No real publication, CRM, client acceptance or deploy.\n";
