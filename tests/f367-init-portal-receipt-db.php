<?php

declare(strict_types=1);

/** F1-367: initialize *only* second disposable PG18 fixture on port 5433. */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F367_PORTAL_DB_LAB')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_PORT')!=='5432' ||
    getenv('DB_DATABASE')!=='sites_f360_lab') {
    fwrite(STDERR,"F1-367: refusing non-CI or non-disposable target.\n");
    exit(1);
}
$pdo=new PDO(
    'pgsql:host=127.0.0.1;port=5433;dbname=portal_f367_lab;connect_timeout=5',
    'postgres','f367-fictional-portal-db-only',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_EMULATE_PREPARES=>false,
     PDO::ATTR_TIMEOUT=>6]
);
$version=(int)$pdo->query('SHOW server_version_num')->fetchColumn();
$name=(string)$pdo->query('SELECT current_database()')->fetchColumn();
if($version<180000 || $version>=190000 || $name!=='portal_f367_lab') {
    throw new RuntimeException('F1-367: isolated PostgreSQL18 Portal fixture required.');
}
$fixture=__DIR__.'/fixtures/f367-portal-receipt-db.sql';
if(!is_file($fixture) || is_link($fixture)) {
    throw new RuntimeException('F1-367: missing/unsafe fixture SQL.');
}
$pdo->exec((string)file_get_contents($fixture));
$count=(int)$pdo->query(
    'SELECT count(*) FROM portal_f367.receipts'
)->fetchColumn();
if($count!==0)throw new RuntimeException('F1-367: receiver fixture not empty.');
echo "F1-367 separate disposable Portal PostgreSQL18 ledger initialized.\n";
