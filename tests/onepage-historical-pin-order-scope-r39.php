<?php
declare(strict_types=1);

// Reuse the existing disposable service, exact synthetic order and R38
// appender. This changes only the CI fixture policy, not an operational grant.
require __DIR__.'/onepage-historical-pin-append-only-r38.php';

$r39Checks=0;
$verify=static function(bool $ok,string $case)use(&$r39Checks):void {
    if (!$ok) throw new RuntimeException('R39 '.$case);
    ++$r39Checks;
};
$writer=$connect('r38_appender','r38_disposable_appender');
$otherOrder=array_replace($new,['orderRef'=>'fixture-r39-other-order','acceptanceRef'=>'fixture-r39-other-order-click']);
$otherSite=array_replace($new,['siteRef'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','acceptanceRef'=>'fixture-r39-other-site-click']);
$verify($otherOrder['orderRef']!==$new['orderRef'] && $otherSite['siteRef']!==$new['siteRef'],'distinct resource selectors');
$attempts=[$otherOrder,$otherSite,array_replace($otherOrder,['siteRef'=>$otherSite['siteRef']])];

// Demonstrate the actual R38 fixture limitation without persisting foreign
// evidence: coherent payload+selector for another resource passed its RLS.
foreach ($attempts as $candidate) {
    $writer->beginTransaction();
    try {
        $append($writer,$selector($candidate),$candidate);
        $verify($readPin($a,$selector($candidate))===null,'uncommitted out-of-order append invisible');
    } finally {
        $writer->rollBack();
    }
    $verify($readPin($a,$selector($candidate))===null,'baseline probe rolled back');
}
$writer=null;

// Restrict the test appender to the already admitted resource, not merely
// its tenant/buyer. No request-controlled GUC, no new runtime identity.
$admin=$connect('postgres','r36_disposable_admin');
$admin->exec("ALTER POLICY r38_append_scope ON r36_lab.historical_pin WITH CHECK (tenant_ref = ".
    $admin->quote($new['tenantRef'])." AND buyer_ref = ".$admin->quote($new['buyerRef']).
    " AND order_ref = ".$admin->quote($new['orderRef'])." AND site_ref = ".$admin->quote($new['siteRef']).")");
$admin=null;
$writer=$connect('r38_appender','r38_disposable_appender');
foreach ($attempts as $candidate) {
    $denied=false;
    try {
        $append($writer,$selector($candidate),$candidate);
    } catch(PDOException $e) {
        $denied=$e->getCode()==='42501';
    }
    $verify($denied,'coherent wrong order/site denied by RLS');
    $verify($readPin($a,$selector($candidate))===null,'no denied append persisted');
}
$exact=array_replace($new,['acceptanceRef'=>'fixture-r39-scoped-click']);
$writer->beginTransaction();
$append($writer,$selector($exact),$exact);
$verify($readPin($a,$selector($exact))===null,'exact scoped append not visible before commit');
$writer->commit();
$writer=null;
$a=$connect('r36_reader_a','r36_disposable_reader');
$read=$readPin($a,$selector($exact));
$verify(is_array($read) && count($read)===count($exact) && array_diff_assoc($exact,$read)===[],'exact scoped append durable after reconnect');
$verify($readPin($b,$selector($exact))===null && $readPin($c,$selector($exact))===null,'other buyer and tenant cannot read scoped append');
$verify($readPin($a,$keys)===$stored && $readPin($a,$selector($pin))===$loaded,'prior historical records unchanged');
$verify((int)$a->query('SELECT count(*) FROM r36_lab.historical_pin')->fetchColumn()===3,'only expected scoped records persisted');
echo "ONEPAGE R39 PG18 ORDER SCOPE PASS: {$r39Checks} synthetic checks; NO-GO.\n";
