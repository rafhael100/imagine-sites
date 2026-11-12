<?php

declare(strict_types=1);

/**
 * F1-140: CI-only PostgreSQL 18 transaction/row-lock proof.
 * There are no real leads, IAM, consent, CRM, HTTP endpoints, or delivery ACKs.
 */
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('SITES_F140_PG_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'sites_f140_atomic_lab') {
    fwrite(STDERR, "F1-140 requires disposable CI PostgreSQL 18 lab.\n");
    exit(1);
}

function f140Db(string $user, string $password): PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
        $user, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function f140Check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) {
        throw new RuntimeException('F1-140 FAILED: '.$label);
    }
    $checks++;
    echo 'PASS: '.$label."\n";
}

function f140Claim(PDO $db, array $a): string {
    $q = $db->prepare('SELECT f140_lab.try_claim(?,?,?,?,?,?,?)');
    $q->execute([
        $a['org'], $a['sub'], $a['binding'],
        $a['site'], $a['form'], $a['dest'], $a['revision'],
    ]);
    return (string) $q->fetchColumn();
}

function f140ExpectedSqlState(callable $fn, string $sqlState): bool {
    try {
        $fn();
    } catch (PDOException $error) {
        return $error->getCode() === $sqlState;
    }
    return false;
}

$checks = 0;
$a = [
    'org' => '11111111-1111-4111-8111-111111111111',
    'sub' => '33333333-3333-4333-8333-333333333333',
    'binding' => '22222222-2222-4222-8222-222222222222',
    'site' => '44444444-4444-4444-8444-444444444444',
    'form' => '55555555-5555-4555-8555-555555555555',
    'dest' => '66666666-6666-4666-8666-666666666666',
    'revision' => 7,
];
$b = static fn(array $changes = []): array => array_replace($a, $changes);
$app = f140Db('sites_f140_app', 'f140-fictional-ci-only');
$competitor = f140Db('sites_f140_app', 'f140-fictional-ci-only');
$admin = f140Db('postgres', 'f140-fictional-admin-ci-only');

$identity = $app->query("SELECT current_user, current_database()")->fetch(PDO::FETCH_NUM);
f140Check($identity[0] === 'sites_f140_app' && $identity[1] === 'sites_f140_atomic_lab',
    'restricted login uses disposable lab only');
foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
    $q = $app->prepare(
        "SELECT has_table_privilege(current_user,'f140_lab.synthetic_claims',?)::int"
    );
    $q->execute([$privilege]);
    f140Check((int) $q->fetchColumn() === 0,
        'app cannot directly '.$privilege.' synthetic claim rows');
}
f140Check(
    f140ExpectedSqlState(
        static fn() => $app->query('SELECT * FROM f140_lab.synthetic_binding_versions'),
        '42501'
    ),
    'app cannot directly read synthetic authority rows'
);

$app->beginTransaction();
f140Check(f140Claim($app, $a) === 'CLAIM_RECORDED_UNVERIFIED',
    'binding revision 7 and metadata match creates unverified technical claim');

$competitor->exec("SET lock_timeout='250ms'");
$competitor->beginTransaction();
f140Check(
    f140ExpectedSqlState(static fn() => f140Claim($competitor, $a), '55P03'),
    'second physical receiver cannot claim same uncommitted submission'
);
$competitor->rollBack();

$admin->exec("SET lock_timeout='250ms'");
$f140UpdateRevision = static function(PDO $db, int $revision) use($a): void {
    $q = $db->prepare(
        'UPDATE f140_lab.synthetic_binding_versions SET revision=? '.
        'WHERE organization_reference=? AND binding_reference=?'
    );
    $q->execute([$revision, $a['org'], $a['binding']]);
};
f140Check(
    f140ExpectedSqlState(static fn() => $f140UpdateRevision($admin, 8), '55P03'),
    'admin revocation/revision update must wait for in-flight locked registration'
);
$app->commit();
$admin->exec("SET lock_timeout='0'");
f140Check(f140Claim($competitor, $a) === 'EXISTING_CLAIM_UNVERIFIED',
    'exact replay leaves one claim and grants no ACK');

$f140UpdateRevision($admin, 8);
f140Check(f140Claim($app, $a) === 'REVISION_CHANGED',
    'original submission cannot replay with stale revision 7');
f140Check(f140Claim($app, $b(['sub'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']))
    === 'REVISION_CHANGED',
    'new submission cannot use revoked revision 7');
f140Check(
    f140Claim($app, $b([
        'sub'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'revision'=>8,
    ])) === 'CLAIM_RECORDED_UNVERIFIED',
    'new revision 8 can create a technical claim, still no lead delivery'
);
f140Check(
    f140Claim($app, $b([
        'sub'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'revision'=>8,
    ])) === 'EXISTING_CLAIM_UNVERIFIED',
    'same submission and revision returns unverified replay'
);

$mutate = $admin->prepare(
    'UPDATE f140_lab.synthetic_binding_versions '.
    'SET destination_reference=?,revision=9 '.
    'WHERE organization_reference=? AND binding_reference=?'
);
$mutate->execute([
    '77777777-7777-4777-8777-777777777777',
    $a['org'], $a['binding'],
]);
f140Check(
    f140Claim($app, $b([
        'sub'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'revision'=>9,
    ])) === 'BINDING_CONTEXT_MISMATCH',
    'same revision cannot silently reassign original destination'
);
f140Check(
    f140Claim($app, $b([
        'sub'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'revision'=>9,
        'dest'=>'77777777-7777-4777-8777-777777777777',
    ])) === 'CLAIM_RECORDED_UNVERIFIED',
    'current metadata can only write synthetic technical claim'
);
f140Check(
    f140Claim($app, $b([
        'sub'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'revision'=>9,
        'dest'=>'88888888-8888-4888-8888-888888888888',
    ])) === 'BINDING_CONTEXT_MISMATCH',
    'metadata changes always fail even when submission already exists'
);

$admin->exec(
    "UPDATE f140_lab.synthetic_binding_versions SET active=false,revision=10"
);
f140Check(
    f140Claim($app, $b([
        'sub'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'revision'=>10,
        'dest'=>'77777777-7777-4777-8777-777777777777',
    ])) === 'BINDING_INACTIVE',
    'revoked binding blocks a new technical claim'
);
f140Check(
    f140Claim($app, $b([
        'binding'=>'99999999-9999-4999-8999-999999999999',
        'sub'=>'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        'revision'=>10,
    ])) === 'BINDING_NOT_FOUND',
    'unknown binding blocks claim'
);
f140Check(
    f140Claim($app, $b([
        'sub'=>'ffffffff-ffff-4fff-8fff-ffffffffffff',
        'revision'=>0,
    ])) === 'INVALID_SYNTHETIC_CLAIM',
    'invalid revision fails closed'
);
f140Check(
    (int) $admin->query('SELECT count(*) FROM f140_lab.synthetic_claims')->fetchColumn() === 3,
    'only three metadata-only claims exist after stale/revoked attempts'
);
echo "\n$checks F1-140 PG18 transaction/uniqueness and revision assertions passed.\n";
echo "No external IAM, authenticated tenant, real lead, CRM receipt, ACK or deploy.\n";
