<?php

declare(strict_types=1);

/** F1-46: one synthetic signed JSON fixture; never use as a Portal event. */
if (PHP_SAPI !== 'cli' || getenv('SITES_F46_WIRE') !== '1') {
    fwrite(STDERR,"F1-46 synthetic wire emitter requires explicit lab flag.\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix='Imagine\\Sites\\';
    if (!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if (!preg_match('/\A(?:Security|Production|Application)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)) {
        throw new RuntimeException('Unsafe synthetic PHP class reference');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});

use Imagine\Sites\Production\SyntheticProductionWorkflow as Workflow;
use Imagine\Sites\Security\SyntheticReviewHandoffEnvelope as Envelope;

$key='F46-LAB-ONLY-INTEROPERABILITY-KEY-DO-NOT-DEPLOY-0000000000000';
$now=1790000000;
$workflow=Workflow::create('tenant-f46-demo','site-f46-demo','workflow-f46-demo')
    ->advance('agent','start_draft',0)
    ->advance('agent','submit_draft',1,str_repeat('a',64))
    ->advance('imagine_reviewer','approve_internal',2,str_repeat('a',64));
$wire=Envelope::sign(
    $workflow,'release-f46-demo',$key,'f46-cross-language-nonce-001',$now,$now+60
);
echo json_encode($wire,
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
