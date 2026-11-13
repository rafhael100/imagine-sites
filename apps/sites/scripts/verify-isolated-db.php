<?php

declare(strict_types=1);

/**
 * F1-84: direct PDO proof of an internal-only PostgreSQL 18 lab with
 * *restricted* DB app credentials. No web route, migrations, customer data,
 * permanent writes, secrets output, or production claim.
 */
if (
    PHP_SAPI !== 'cli' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('SITES_F84_LAB') !== '1' ||
    getenv('DB_CONNECTION') !== 'pgsql' ||
    getenv('DB_HOST') !== 'sites-lab-db' ||
    getenv('DB_PORT') !== '5432' ||
    getenv('DB_DATABASE') !== 'sites_lab' ||
    getenv('DB_USERNAME') !== 'siteslab' ||
    !is_string(getenv('DB_PASSWORD')) ||
    strlen((string)getenv('DB_PASSWORD')) < 12
) {
    fwrite(STDERR, "F1-84 restricted CI lab mode and connection contract required.\n");
    exit(1);
}

try {
    $db = new PDO(
        'pgsql:host=sites-lab-db;port=5432;dbname=sites_lab;connect_timeout=5',
        'siteslab',
        (string)getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 6,
        ]
    );
    $q = $db->query(
        "SELECT current_database() AS db, current_user AS actor,
            current_setting('server_version_num')::integer AS version,
            inet_server_addr()::text AS server_address,
            r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolbypassrls,
            has_database_privilege(current_user, 'sites_lab', 'CREATE') AS can_create_db_objects,
            has_database_privilege(current_user, 'sites_lab', 'TEMPORARY') AS can_use_temp,
            has_schema_privilege(current_user, 'public', 'CREATE') AS can_create_public
         FROM pg_catalog.pg_roles r WHERE r.rolname=current_user"
    );
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $pgTrue = static fn(mixed $v): bool => in_array($v, [true, 't', '1'], true);
    $pgFalse = static fn(mixed $v): bool => in_array($v, [false, 'f', '0'], true);
    if (
        !is_array($row) ||
        $row['db'] !== 'sites_lab' ||
        $row['actor'] !== 'siteslab' ||
        (int)$row['version'] < 180000 ||
        (int)$row['version'] >= 190000 ||
        !is_string($row['server_address']) ||
        $row['server_address'] === '' ||
        !$pgFalse($row['rolsuper']) ||
        !$pgFalse($row['rolcreatedb']) ||
        !$pgFalse($row['rolcreaterole']) ||
        !$pgFalse($row['rolbypassrls']) ||
        !$pgFalse($row['can_create_db_objects']) ||
        !$pgFalse($row['can_create_public']) ||
        !$pgTrue($row['can_use_temp'])
    ) {
        throw new RuntimeException('Unexpected or elevated PostgreSQL lab role.');
    }

    // The connection is real and permits ONLY disposable session state here.
    $db->beginTransaction();
    try {
        $db->exec('CREATE TEMPORARY TABLE sites_f84_probe (n integer NOT NULL) ON COMMIT DROP');
        $db->exec('INSERT INTO sites_f84_probe(n) VALUES (84)');
        if ((int)$db->query('SELECT n FROM sites_f84_probe')->fetchColumn() !== 84) {
            throw new RuntimeException('PDO temporary transaction roundtrip failed');
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    if ($db->query("SELECT to_regclass('pg_temp.sites_f84_probe')")->fetchColumn() !== null) {
        throw new RuntimeException('Temporary test table survived rollback');
    }
    echo "PASS F1-84: Docker PHP app reached PG18 as a NON-superuser through internal network.\n";
    echo "PASS F1-84: database/schema CREATE denied, TEMP transaction rolled back, no permanent writes.\n";
    echo "NOT IAM, backup/restore attestation, customer approval or production publication.\n";
} catch (Throwable) {
    fwrite(STDERR, "FAIL F1-84: restricted lab PostgreSQL connection or privilege proof denied.\n");
    exit(1);
}
