<?php
declare(strict_types=1);

// Transaction experiment ONLY, reusing the exact admitted R39 resource,
// existing history and scoped disposable appender. No Portal source,
// authentication, business authorization, operational DDL or publication.
require __DIR__.'/onepage-historical-pin-order-scope-r39.php';

$r41=0;
$verify41=static function(bool $ok,string $case)use(&$r41):void {
    if (!$ok) throw new RuntimeException('R41 '.$case);
    ++$r41;
};
$admin=$connect('postgres','r36_disposable_admin');
$admin->exec("CREATE TABLE r36_lab.cas_fixture (
    tenant_ref text NOT NULL, order_ref text NOT NULL, site_ref text NOT NULL,
    buyer_ref text NOT NULL, generation bigint NOT NULL CHECK(generation>0),
    proof text NOT NULL, active boolean NOT NULL,
    PRIMARY KEY(tenant_ref,order_ref,site_ref,buyer_ref)
);
REVOKE ALL ON r36_lab.cas_fixture FROM PUBLIC;
ALTER TABLE r36_lab.cas_fixture ENABLE ROW LEVEL SECURITY;
ALTER TABLE r36_lab.cas_fixture FORCE ROW LEVEL SECURITY;
GRANT SELECT,UPDATE(generation) ON r36_lab.cas_fixture TO r38_appender;");
$scope=array_slice($selector($pin),0,4);
$where="tenant_ref=".$admin->quote($scope[0])." AND order_ref=".$admin->quote($scope[1]).
    " AND site_ref=".$admin->quote($scope[2])." AND buyer_ref=".$admin->quote($scope[3]);
$admin->exec("CREATE POLICY r41_exact_scope ON r36_lab.cas_fixture FOR ALL TO r38_appender USING ($where) WITH CHECK ($where)");
$q=$admin->prepare("INSERT INTO r36_lab.cas_fixture VALUES (?,?,?,?,1,?,true)");
$q->execute([...$scope,$pin['proofDigest']]);
$writer=$connect('r38_appender','r38_disposable_appender');
$writer->exec("SET lock_timeout='500ms'");
$verify41($writer->query("SELECT NOT has_table_privilege(current_user,'r36_lab.historical_pin','SELECT,UPDATE,DELETE,TRUNCATE')")->fetchColumn()===true,'history remains append-only');
$verify41($writer->query("SELECT NOT has_column_privilege(current_user,'r36_lab.cas_fixture','active','UPDATE') AND NOT has_column_privilege(current_user,'r36_lab.cas_fixture','proof','UPDATE')")->fetchColumn()===true,'appender cannot change fixture approval or proof');
$generation=static fn():int=>(int)$admin->query("SELECT generation FROM r36_lab.cas_fixture")->fetchColumn();

// Compare-and-swap of the exact fixture witness and append in ONE transaction.
// The CAS must lock the same row used by the simulated independent revoker.
// The history payload and selector are the already validated R36 fixture,
// not derived from arbitrary caller JSON. This is a SQL property test only.
$cas=static function(PDO $db,int $expected,array $candidate)use($selector,$append):bool {
    $scope=array_slice($selector($candidate),0,4);
    $db->beginTransaction();
    try {
        $q=$db->prepare("UPDATE r36_lab.cas_fixture SET generation=generation+1
          WHERE tenant_ref=? AND order_ref=? AND site_ref=? AND buyer_ref=?
          AND generation=? AND proof=? AND active=true");
        $q->execute([...$scope,$expected,$candidate['proofDigest']]);
        if ($q->rowCount()!==1) { $db->rollBack(); return false; }
        $append($db,$selector($candidate),$candidate);
        $db->commit();
        return true;
    } catch(Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
};
$candidate=array_replace($pin,['acceptanceRef'=>'fixture-r41-cas-click']);
$keys41=$selector($candidate);
$verify41(!$cas($writer,2,$candidate) && $generation()===1 && $readPin($a,$keys41)===null,'wrong generation zero effects');
$wrong=array_replace($candidate,['proofDigest'=>str_repeat('f',64)]);
$verify41(!$cas($writer,1,$wrong) && $generation()===1 && $readPin($a,$keys41)===null,'wrong proof zero effects');
$other=array_replace($candidate,['orderRef'=>'fixture-r41-other-order']);
$verify41(!$cas($writer,1,$other) && $generation()===1 && $readPin($a,$selector($other))===null,'wrong order zero effects');

// A separate connection holds a real PostgreSQL row lock while revoking.
// No sleeps or timing-dependent interleavings; fail closed on lock timeout.
$admin->beginTransaction();
try {
    $admin->exec("UPDATE r36_lab.cas_fixture SET active=false,generation=2");
    $blocked=false;
    try { $cas($writer,1,$candidate); } catch(PDOException $e) { $blocked=$e->getCode()==='55P03'; }
    $verify41($blocked && !$writer->inTransaction() && $readPin($a,$keys41)===null,'contended CAS rolled back without append');
    $admin->commit();
} finally { if ($admin->inTransaction()) $admin->rollBack(); }
$verify41(!$cas($writer,1,$candidate) && !$cas($writer,2,$candidate) && $generation()===2 &&
    $readPin($a,$keys41)===null,'revoked witness denies old and current generation');

// Reset ONLY the synthetic authority fixture for a separate positive case.
// This is not a new approval or click by any user.
$admin->exec("UPDATE r36_lab.cas_fixture SET active=true,generation=3");
$verify41($cas($writer,3,$candidate) && $generation()===4,'one atomic positive commit');
$verify41(!$cas($writer,3,$candidate) && $generation()===4,'stale replay has zero effects');
$duplicate=false;
try { $cas($writer,4,$candidate); } catch(PDOException $e) { $duplicate=$e->getCode()==='23505'; }
$verify41($duplicate && !$writer->inTransaction() && $generation()===4,'duplicate append rolls back generation update');
$writer=null;
$a=$connect('r36_reader_a','r36_disposable_reader');
$seen41=$readPin($a,$keys41);
$verify41(is_array($seen41) && count($seen41)===count($candidate) && array_diff_assoc($candidate,$seen41)===[],
    'independent reader exact durable history');
$verify41($readPin($b,$keys41)===null && $readPin($c,$keys41)===null,'other buyer and tenant denied');
$verify41((int)$a->query('SELECT count(*) FROM r36_lab.historical_pin')->fetchColumn()===4,'one new historical row only');
echo "ONEPAGE R41 PG18 CAS PASS: {$r41} synthetic checks; NO-GO.\n";
