<?php

declare(strict_types=1);

/** F1-232: no network/SQL/creds required to verify migration targeting. */
require __DIR__.'/../src/Persistence/LabMigrationTargetFenceV1.php';

use Imagine\Sites\Persistence\LabMigrationTargetFenceV1 as Target;

$checks=0;
function f232Check(bool $good,string $reason):void {
    global $checks;
    if (!$good)throw new RuntimeException('F1-232 '.$reason);
    ++$checks;
}

$defaults=[
    'lab'=>'f93',
    'environment'=>'testing',
    'githubActions'=>true,
    'optedIn'=>true,
    'driver'=>'pgsql',
    'database'=>'sites_f93_lab',
    'host'=>'127.0.0.1',
    'port'=>'5432',
    'username'=>'postgres',
];

$allowed=static function(array $fields):bool {
    try {Target::assertAllowed(...$fields);return true;}
    catch(DomainException){return false;}
};
$denied=static function(array $fields):bool {
    try {Target::assertAllowed(...$fields);}
    catch(DomainException $e) {
        return $e->getMessage()==='sites_lab_migration_target_denied';
    }
    return false;
};

foreach([
    $defaults,
    array_replace($defaults,['lab'=>'f93','database'=>'sites_f94_lab']),
    array_replace($defaults,['lab'=>'f94','database'=>'sites_f94_lab']),
    array_replace($defaults,['lab'=>'f93','port'=>5432]),
] as $good) {
    f232Check($allowed($good),'explicit CI-only project DB should be accepted');
}

$bad=[
    ['lab'=>'f94','database'=>'sites_f93_lab'],
    ['lab'=>'other'],
    ['environment'=>'local'],
    ['environment'=>'production'],
    ['environment'=>'staging'],
    ['githubActions'=>false],
    ['optedIn'=>false],
    ['driver'=>'sqlite'],
    ['driver'=>'mysql'],
    ['database'=>'postgres'],
    ['database'=>'imagine_crm'],
    ['database'=>'imagine_portal'],
    ['database'=>'imagine_sites_dev_placeholder'],
    ['database'=>'sites_lab'],
    ['host'=>''],
    ['host'=>'localhost'],
    ['host'=>'127.0.0.2'],
    ['host'=>'::1'],
    ['host'=>'db.example.internal'],
    ['host'=>'sites-lab-db'],
    ['port'=>0],
    ['port'=>'5433'],
    ['port'=>null],
    ['username'=>'sites_f94_app'],
    ['username'=>'root'],
    ['username'=>''],
];
foreach($bad as $override) {
    f232Check($denied(array_replace($defaults,$override)),
        'unsafe target or missing CI gate must be rejected before DDL');
}
f232Check($denied(array_replace($defaults,[
    'lab'=>'f94','database'=>'sites_f93_lab'
])),'F1-94 cannot be run on F1-93-only database');

echo $checks." F1-232 isolated migration target assertions passed; no database touched.\n";
