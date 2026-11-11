<?php

declare(strict_types=1);

// F1-141: PG18 CI-only revision monotonicity, no HTTP, IAM, PII or CRM.
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('SITES_F140_PG_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'sites_f140_atomic_lab') {
    fwrite(STDERR, "F1-141 is restricted to the disposable PG18 CI fixture.\n");
    exit(1);
}
function f141Db(string $role, string $password): PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
        $role, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
}
function f141Assert(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('F1-141 failed: '.$label);
    $checks++;
    echo 'PASS: '.$label."\n";
}
function f141Denied(PDO $db, string $sql, array $params=[]): bool {
    try {
        $q=$db->prepare($sql);
        $q->execute($params);
    } catch (PDOException $error) {
        return $error->getCode() === '23514';
    }
    return false;
}
function f141Claim(PDO $db, array $args): string {
    $q=$db->prepare('SELECT f140_lab.try_claim(?,?,?,?,?,?,?)');
    $q->execute($args);
    return (string)$q->fetchColumn();
}

$checks=0;
$org='11111111-1111-4111-8111-111111111111';
$binding='22222222-2222-4222-8222-222222222222';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$destinationA='66666666-6666-4666-8666-666666666666';
$destinationB='77777777-7777-4777-8777-777777777777';
$firstSubmission='33333333-3333-4333-8333-333333333333';
$nextSubmission='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$admin=f141Db('postgres','f140-fictional-admin-ci-only');
$app=f141Db('sites_f140_app','f140-fictional-ci-only');
$claim=static fn(string $sub,int $revision,string $dest):array =>
    [$org,$sub,$binding,$site,$form,$dest,$revision];

f141Assert(
    f141Claim($app,$claim($firstSubmission,7,$destinationA))
        === 'CLAIM_RECORDED_UNVERIFIED',
    'synthetic revision 7 metadata claim before changes'
);

$sql='UPDATE f140_lab.synthetic_binding_versions SET revision=? '.
     'WHERE organization_reference=? AND binding_reference=?';
f141Assert(f141Denied($admin,$sql,[7,$org,$binding]),
    'reusing same revision is forbidden');
f141Assert(f141Denied($admin,$sql,[6,$org,$binding]),
    'revision rollback is forbidden');
f141Assert(f141Denied($admin,
    'UPDATE f140_lab.synthetic_binding_versions SET destination_reference=? '.
    'WHERE organization_reference=? AND binding_reference=?',
    [$destinationB,$org,$binding]),
    'metadata modification without revision bump is forbidden');
f141Assert(f141Denied($admin,
    'UPDATE f140_lab.synthetic_binding_versions SET organization_reference=? '.
    'WHERE organization_reference=? AND binding_reference=?',
    ['88888888-8888-4888-8888-888888888888',$org,$binding]),
    'binding tenant cannot be reassigned');
f141Assert(f141Denied($admin,
    'DELETE FROM f140_lab.synthetic_binding_versions '.
    'WHERE organization_reference=? AND binding_reference=?',
    [$org,$binding]),
    'binding history cannot be deleted via ordinary DELETE');

$mutate=$admin->prepare(
    'UPDATE f140_lab.synthetic_binding_versions '.
    'SET revision=?,destination_reference=? '.
    'WHERE organization_reference=? AND binding_reference=?'
);
$mutate->execute([8,$destinationB,$org,$binding]);
f141Assert($mutate->rowCount()===1,'A to B revision 8 advances');

$mutate->execute([9,$destinationA,$org,$binding]);
f141Assert($mutate->rowCount()===1,'B to A revision 9 advances without recycling revision 7');

f141Assert(
    f141Claim($app,$claim($nextSubmission,7,$destinationA))
        === 'REVISION_CHANGED',
    'A to B to A does not make stale revision 7 valid again'
);
f141Assert(
    f141Claim($app,$claim($nextSubmission,9,$destinationA))
        === 'CLAIM_RECORDED_UNVERIFIED',
    'latest revision 9 writes metadata only, no ACK'
);

$revoke=$admin->prepare(
    'UPDATE f140_lab.synthetic_binding_versions SET revision=10,active=false '.
    'WHERE organization_reference=? AND binding_reference=?'
);
$revoke->execute([$org,$binding]);
f141Assert($revoke->rowCount()===1,'revocation advances to revision 10');
f141Assert(f141Denied($admin,
    'UPDATE f140_lab.synthetic_binding_versions SET revision=11,active=true '.
    'WHERE organization_reference=? AND binding_reference=?',
    [$org,$binding]),
    'revoked synthetic binding cannot reactivate on the same identity');
f141Assert(
    f141Claim($app,$claim('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',10,$destinationA))
        === 'BINDING_INACTIVE',
    'revoked binding cannot create new technical claims'
);

$state=$admin->query('SELECT revision, active FROM f140_lab.synthetic_binding_versions');
$record=$state->fetch(PDO::FETCH_ASSOC);
f141Assert((int)$record['revision']===10 && $record['active']===false,
    'latest revision and revocation remain stored');
f141Assert(
    (int)$admin->query('SELECT count(*) FROM f140_lab.synthetic_claims')->fetchColumn()===2,
    'failed revisions and revoked requests did not create claims'
);

echo "\n$checks F1-141 PG18 monotonic revision and revocation tests passed.\n".
    "No authenticated Portal/tenant, real lead, CRM receipt or deployment.\n";
