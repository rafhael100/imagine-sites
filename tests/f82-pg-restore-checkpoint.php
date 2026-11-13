<?php

declare(strict_types=1);

/** F1-82: retain signed-less anchor outside a DISPOSABLE PG18 database. */
if(PHP_SAPI!=='cli' || getenv('SITES_F79_PG_LAB')!=='1' ||
    !isset($argv[1]) ||
    !in_array($argv[1],['baseline','append','extended','restored'],true)) {
    fwrite(STDERR,"F1-82 accepts only explicit disposable PG18 test phases.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production|Persistence)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$name)){
        throw new RuntimeException('Unsafe F1-82 class name');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$name).'.php';
});
use Imagine\Sites\Persistence\LabReviewLedgerCheckpoint as Checkpoint;
use Imagine\Sites\Persistence\LabInternalReviewDecisionStore as Store;
use Imagine\Sites\Persistence\LabPersistedReviewEvidence as Evidence;
use Imagine\Sites\Production\SyntheticStructuredDraftAssembly as Assembly;
use Imagine\Sites\Production\SyntheticInternalReviewDossier as Dossier;
use Imagine\Sites\Production\SyntheticInternalReviewDecision as Decision;

$phase=$argv[1];
$connect=static fn()=>new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=sites_f79_lab',
    'postgres','f79-lab-ephemeral-fixture-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$db=$connect();
$db->exec('SET ROLE sites_f79_writer_a');
$other=$connect();
$other->exec('SET ROLE sites_f79_writer_b');
$owner=$connect();
$baselinePath='/tmp/sites-f82-baseline-anchor.json';
$extendedPath='/tmp/sites-f82-extended-anchor.json';
function f82Assert(bool $condition,string $label):void {
    if(!$condition) throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
function f82Read(string $path):array {
    if(!is_file($path) || is_link($path))throw new RuntimeException('Missing safe external checkpoint');
    $bytes=(string)file_get_contents($path);
    if(strlen($bytes)>1024)throw new RuntimeException('Oversized checkpoint file');
    $value=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);
    if(!is_array($value))throw new RuntimeException('Invalid checkpoint');
    return $value;
}
function f82Write(string $path,array $anchor):void {
    if(file_exists($path) || is_link($path))throw new RuntimeException('Refusing anchor overwrite');
    $json=json_encode($anchor,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    $old=umask(0077);
    try{
        $h=fopen($path,'x');
        if(!$h)throw new RuntimeException('Unable to create outside-DB anchor');
        $size=fwrite($h,$json);fclose($h);
        if($size!==strlen($json))throw new RuntimeException('Incomplete anchor write');
    }finally{umask($old);}
    chmod($path,0600);
}

if($phase==='baseline'){
    $anchor=Checkpoint::capture($db,'tenant-f79-a');
    f82Assert($anchor['rowCount']>=2 && $anchor['throughId']>=2,
        'initial checkpoint covers prior private tenant A review history');
    f82Assert(Checkpoint::verify($db,$anchor),'baseline anchor verifies before backup');
    f82Assert(!Checkpoint::verify($other,$anchor),'B cannot verify foreign A anchor');
    try{Checkpoint::capture($owner,'tenant-f79-a');throw new RuntimeException('FAIL owner accepted');}
    catch(DomainException){echo "PASS lab owner cannot generate a tenant anchor\n";}
    f82Write($baselinePath,$anchor);
    echo "F1-82 baseline checkpoint held outside DB.\n";
} elseif($phase==='append'){
    $copy=[
        'schemaVersion'=>'sites.ai-copy.v1',
        'headline'=>'Soluções digitais para pequenos negócios',
        'description'=>'Demonstração inteiramente fictícia com serviços, informações de atendimento, benefícios e detalhes para uma prévia de revisão.',
        'callToAction'=>'Conheça os serviços',
        'benefits'=>['Conteúdo claro','Orientação útil','Organização'],
    ];
    $draft=Assembly::build('onepage','Marca de Teste','modern',$copy,
        'tenant-f79-a','site-f79-a','workflow-f82-after-backup','release-f82-after-backup');
    $warnings=[];
    foreach($draft->editorialReport->checks as $key=>$pass)if(!$pass)$warnings[]=$key;
    $decision=Decision::decide($draft,'imagine_reviewer','reviewer-synthetic',
        'decision-f82-after-backup','approve_internal',2,$draft->artifact->digest,
        $draft->bundle->bundleDigest,$warnings,null,Dossier::fromDraft($draft)->digest);
    Store::append($db,$decision);
    f82Assert(Evidence::verify($db,$decision)['decisionRecordDigest']===$decision->reviewDigest,
        'new decision verified as persisted AFTER baseline physical dump');
    f82Assert(Checkpoint::verify($db,f82Read($baselinePath)),
        'prior external anchor remains valid after new append');
    echo "F1-82 one synthetic decision appended since backup.\n";
} elseif($phase==='extended'){
    $old=f82Read($baselinePath);
    $new=Checkpoint::capture($db,'tenant-f79-a');
    f82Assert($new['rowCount']===$old['rowCount']+1 &&
        $new['throughId']>$old['throughId'] &&
        $new['prefixSha256']!==$old['prefixSha256'],
        'extended checkpoint reflects exactly one new review decision');
    f82Assert(Checkpoint::verify($db,$new),'extended checkpoint verifies before physical restore');
    f82Write($extendedPath,$new);
    echo "F1-82 extended anchor retained outside DB, now restore old dump.\n";
} else {
    $old=f82Read($baselinePath);
    $new=f82Read($extendedPath);
    f82Assert(Checkpoint::verify($db,$old),
        'physical restore preserved original tenant A review prefix');
    f82Assert(!Checkpoint::verify($db,$new),
        'physical restore rollback DENIED against separately held newest checkpoint');
    f82Assert(!Checkpoint::verify($other,$old),
        'RLS still denies B from validating A checkpoint after restore');
    $tampered=$old;
    $tampered['prefixSha256']=str_repeat('0',64);
    f82Assert(!Checkpoint::verify($db,$tampered),
        'forged/tampered SHA does not pass restored ledger verification');
    $t=$old;
    $t['tenantRef']='tenant-f79-b';
    f82Assert(!Checkpoint::verify($db,$t),'tenant substitution denied');
    echo "F1-82 real PG dump/restore detected stale history with external lab anchor.\n";
}
echo "F1-82 is laboratory-only, not an externally secured monotonic authority.\n";
