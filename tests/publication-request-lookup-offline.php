<?php

declare(strict_types=1);

// Syntax, invalid inputs and bounded error contracts only. Positive SQL
// operationRef recovery is proven separately against a disposable PG18.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Publication\{PgPublicationJournal,PrivatePublicationRequestLookup};

$checks = 0;
$ok = static function (bool $condition,string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Publication request lookup offline failure: '.$label);
    ++$checks;
};
$invalid = static function (callable $fn,string $type,string $message,string $label) use ($ok): void {
    try { $fn(); } catch (Throwable $e) {
        $ok($e::class === $type && $e->getMessage() === $message && $e->getPrevious() === null,$label);
        return;
    }
    $ok(false,$label.' accepted');
};

$journal=(new ReflectionClass(PgPublicationJournal::class))->newInstanceWithoutConstructor();
$site='11111111-1111-4111-8111-111111111111';
foreach ([
    ['invalid','release',1,'acceptance','idem'],
    [$site,'bad release',1,'acceptance','idem'],
    [$site,'release',0,'acceptance','idem'],
    [$site,'release',1,'bad acceptance','idem'],
    [$site,'release',1,'acceptance','bad key'],
    [$site,str_repeat('r',129),1,'acceptance','idem'],
    [$site,'release',1,'acceptance',str_repeat('i',129)],
    [$site,'release',-1,'acceptance','idem'],
] as $i=>$input) {
    $invalid(static fn ()=>$journal->readByRequest(...$input), InvalidArgumentException::class,
        'Publication journal input denied','invalid request selector #'.$i);
}

$lookup=(new ReflectionClass(PrivatePublicationRequestLookup::class))->newInstanceWithoutConstructor();
$target=['host'=>'localhost','port'=>55449,'database'=>'postgres','databaseOid'=>12345,
    'login'=>'pj_lookup_a_reader','serverAddress'=>'127.0.0.1','caSha256'=>str_repeat('a',64)];
$invoke=static fn (array $t,string $ca,string $siteRef='11111111-1111-4111-8111-111111111111',string $release='release',int $revision=1,
    string $acceptance='acceptance',string $idempotency='idem'): array =>
    $lookup->readExact($t,$ca,$siteRef,$release,$revision,$acceptance,$idempotency);
foreach ([
    ['target'=>array_replace($target,['login'=>'root;SQL'])],
    ['target'=>array_replace($target,['databaseOid'=>0])],
    ['target'=>array_replace($target,['host'=>'localhost;sslmode=disable'])],
    ['target'=>array_replace($target,['serverAddress'=>'unexpected-host'])],
    ['target'=>array_replace($target,['extra'=>'not-allowed'])],
    ['target'=>$target,'ca'=>str_repeat('b',64)],
    ['target'=>$target,'siteRef'=>'invalid'],
    ['target'=>$target,'release'=>'bad release'],
    ['target'=>$target,'revision'=>0],
    ['target'=>$target,'acceptance'=>'bad acceptance'],
    ['target'=>$target,'idempotency'=>'bad key'],
] as $i=>$delta) {
    $invalid(static fn ()=>$invoke($delta['target'], $delta['ca'] ?? str_repeat('a',64),
        $delta['siteRef'] ?? $site, $delta['release'] ?? 'release', $delta['revision'] ?? 1,
        $delta['acceptance'] ?? 'acceptance', $delta['idempotency'] ?? 'idem'),
        RuntimeException::class,'PUBLICATION_LOOKUP_DENIED','bounded private invalid selector #'.$i);
}

// Static checks of the additive migration are not a substitute for executing
// SQL; they prevent accidental changes in documented non-operational scope.
$up=file_get_contents(__DIR__.'/../contracts/publication-journal/002-read-request.sql');
$down=file_get_contents(__DIR__.'/../contracts/publication-journal/002-read-request-down.sql');
$ok(is_string($up) && str_contains($up,'CREATE FUNCTION sites_publication.read_request(') &&
    str_contains($up,'CREATE OR REPLACE FUNCTION sites_publication.journal_runtime_safe') &&
    str_contains($up,"ELSE ARRAY['read_operation','read_request'] END;") &&
    str_contains($up,"WHEN obj.proname IN ('read_operation','read_request') THEN 'sites_publication_reader'") &&
    str_contains($up,"SELECT * INTO actor FROM sites_publication.journal_actor('reader',false)"),
    'additive SQL recognises only the reader duty and exact guard whitelist');
$ok(is_string($down) &&
    str_contains($down,'DROP FUNCTION sites_publication.read_request(uuid,text,bigint,text,text)') &&
    str_contains($down,"ELSE ARRAY['read_operation'] END;") &&
    !str_contains($down,'DROP TABLE') && !str_contains($down,'CASCADE'),
    'down restores legacy allowlist without dropping journal tables');
echo "PUBLICATION REQUEST LOOKUP OFFLINE PASS: {$checks} checks; no SQL result fabricated.\n";
