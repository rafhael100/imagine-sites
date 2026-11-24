<?php
declare(strict_types=1);

// Reuse the exact R36 fixture and its independent PG18 readers. This is
// deliberately a disposable database privilege experiment, not a Portal writer,
// authenticated click, source of approval or runtime migration.
require __DIR__.'/onepage-historical-pin-pg18-r36.php';

$r38Checks = 0;
$assert = static function(bool $ok, string $label) use (&$r38Checks): void {
    if (!$ok) throw new RuntimeException('R38 '.$label);
    ++$r38Checks;
};
$admin = $connect('postgres','r36_disposable_admin');
// The new constraint checks actual JSON types and rejects SQL NULL / missing
// keys. Merely matching the indexed selector must not bind a different payload.
$admin->exec("ALTER TABLE r36_lab.historical_pin ADD CONSTRAINT r38_exact_selector CHECK (
    ((payload->'tenantRef') = to_jsonb(tenant_ref) AND
     (payload->'orderRef') = to_jsonb(order_ref) AND
     (payload->'siteRef') = to_jsonb(site_ref) AND
     (payload->'buyerRef') = to_jsonb(buyer_ref) AND
     (payload->'acceptanceRef') = to_jsonb(acceptance_ref)) IS TRUE
);
CREATE ROLE r38_appender LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD 'r38_disposable_appender';
GRANT USAGE ON SCHEMA r36_lab TO r38_appender;
GRANT INSERT ON r36_lab.historical_pin TO r38_appender;");
$admin->exec("CREATE POLICY r38_append_scope ON r36_lab.historical_pin FOR INSERT TO r38_appender WITH CHECK (tenant_ref = ".
    $admin->quote($tenant)." AND buyer_ref = 'buyer-one')");
$admin=null;
$writer=$connect('r38_appender','r38_disposable_appender');
$assert($writer->query("SELECT current_user='r38_appender' AND NOT rolsuper AND NOT rolbypassrls FROM pg_roles WHERE rolname=current_user")->fetchColumn()===true,'isolated appender identity');
$assert($writer->query("SELECT has_table_privilege(current_user,'r36_lab.historical_pin','INSERT') AND NOT has_table_privilege(current_user,'r36_lab.historical_pin','SELECT,UPDATE,DELETE,TRUNCATE')")->fetchColumn()===true,'append only privilege');

$append=static function(PDO $db,array $keys,array $payload):void {
    $q=$db->prepare('INSERT INTO r36_lab.historical_pin VALUES (?,?,?,?,?,?::jsonb)');
    $q->execute([...$keys,json_encode($payload,JSON_THROW_ON_ERROR)]);
};
$refuse=static function(Closure $attempt,string $sqlstate,string $label)use($assert):void {
    try {$attempt();} catch(PDOException $e) {
        $assert($e->getCode()===$sqlstate,$label);
        return;
    }
    throw new RuntimeException('R38 unexpectedly allowed '.$label);
};
$new=array_replace($pin,['acceptanceRef'=>'fixture-r38-new-acceptance']);
$keys=$selector($new);
$writer->beginTransaction();
$append($writer,$keys,$new);
$assert($readPin($a,$keys)===null,'uncommitted append invisible');
$writer->commit();
$assert($readPin($a,$keys)!==null,'committed append readable');
$assert($readPin($b,$keys)===null && $readPin($c,$keys)===null,'independent readers retain buyer and tenant isolation');
$stored=$readPin($a,$keys);
$assert(count($stored)===count($new) && array_diff_assoc($new,$stored)===[],'independent exact readback');

// No overwrite even for an identical selector and different evidence.
$refuse(fn()=>$append($writer,$keys,$new),'23505','duplicate exact record');
$refuse(fn()=>$append($writer,$keys,array_replace($new,['proofDigest'=>str_repeat('a',64)])),'23505','duplicate cannot replace proof');
foreach (['tenantRef','orderRef','siteRef','buyerRef','acceptanceRef'] as $field) {
    $candidate=array_replace($new,['acceptanceRef'=>'fixture-r38-invalid-'.$field]);
    $candidateKeys=$selector($candidate);
    $wrong=array_replace($candidate,[$field=>'wrong-r38']);
    $refuse(fn()=>$append($writer,$candidateKeys,$wrong),'23514','payload selector mismatch '.$field);
    unset($candidate[$field]);
    $refuse(fn()=>$append($writer,$candidateKeys,$candidate),'23514','payload selector absent '.$field);
}
foreach (['buyerRef'=>'buyer-two','tenantRef'=>$otherTenant] as $field=>$value) {
    $foreignCandidate=array_replace($new,[$field=>$value,'acceptanceRef'=>'fixture-r38-foreign-'.$field]);
    $refuse(fn()=>$append($writer,$selector($foreignCandidate),$foreignCandidate),'42501','scope denial '.$field);
}
foreach (['SELECT payload FROM','UPDATE','DELETE FROM','TRUNCATE'] as $op) {
    $sql=$op.' r36_lab.historical_pin'.($op==='UPDATE'?" SET payload='{}'::jsonb":'');
    $refuse(fn()=>$writer->exec($sql),'42501','writer cannot '.$op);
}
$writer->beginTransaction();
$rolled=array_replace($new,['acceptanceRef'=>'fixture-r38-rolled-back']);
$append($writer,$selector($rolled),$rolled);
$writer->rollBack();
$assert($readPin($a,$selector($rolled))===null,'rolled back append has no effect');
$writer=null;
$a=$connect('r36_reader_a','r36_disposable_reader');
$assert($readPin($a,$keys)===$stored && $readPin($a,$selector($pin))===$loaded,'both historical records intact after failed mutations and reconnect');
$assert((int)$a->query('SELECT count(*) FROM r36_lab.historical_pin')->fetchColumn()===2,'exactly two scoped records, no failed append effects');
echo "ONEPAGE R38 PG18 APPEND BOUNDARY PASS: {$r38Checks} synthetic checks; NO-GO.\n";
