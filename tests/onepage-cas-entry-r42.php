<?php
declare(strict_types=1);

// Disposable SQL privilege experiment, not a Portal writer or buyer click.
// Reuse the admitted R41 resource, CAS fixture and independent readers.
require __DIR__.'/onepage-historical-cas-r41.php';

$r42=0;
$check42=static function(bool $ok,string $label)use(&$r42):void {
    if (!$ok) throw new RuntimeException('R42 '.$label);
    ++$r42;
};
$admin=$connect('postgres','r36_disposable_admin');
$writer=$connect('r38_appender','r38_disposable_appender');
$next=array_replace($candidate,['acceptanceRef'=>'fixture-r42-exact-click']);
$nextKeys=$selector($next);
$admin->exec("UPDATE r36_lab.cas_fixture SET active=false,generation=5");

// Reproduce the R41 limitation using the actual restricted SQL login:
// the callable PHP CAS is not a database boundary. Never commit this probe.
$writer->beginTransaction();
try {
    $append($writer,$nextKeys,$next);
    $check42($writer->query("SELECT generation FROM r36_lab.cas_fixture")->fetchColumn()==5,
        'baseline direct append bypasses revoked CAS');
} finally { $writer->rollBack(); }
$check42($readPin($a,$nextKeys)===null,'baseline probe rolled back');

// Move both mutations behind one fixed-fixture function with a NOLOGIN,
// non-bypass-RLS owner. No caller-supplied payload, scope or proof. This is
// explicitly NOT an operational function, grant, migration or authority.
$admin->exec("CREATE ROLE r42_executor NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE ON SCHEMA r36_lab TO r42_executor;
GRANT SELECT ON r36_lab.cas_fixture TO r42_executor;
GRANT UPDATE(generation) ON r36_lab.cas_fixture TO r42_executor;
GRANT INSERT ON r36_lab.historical_pin TO r42_executor;
REVOKE INSERT ON r36_lab.historical_pin FROM r38_appender;
REVOKE SELECT ON r36_lab.cas_fixture FROM r38_appender;
REVOKE UPDATE(generation) ON r36_lab.cas_fixture FROM r38_appender;");
$admin->exec("CREATE POLICY r42_exact_cas ON r36_lab.cas_fixture FOR ALL TO r42_executor USING ($where) WITH CHECK ($where);
CREATE POLICY r42_exact_append ON r36_lab.historical_pin FOR INSERT TO r42_executor WITH CHECK ($where)");
$quoted=implode(',',array_map(fn($v)=>$admin->quote($v),$nextKeys));
$payload=$admin->quote(json_encode($next,JSON_THROW_ON_ERROR));
$proof=$admin->quote($next['proofDigest']);
$sql=<<<'SQL'
CREATE FUNCTION r36_lab.append_exact_r42(expected bigint) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog
AS $body$
BEGIN
    UPDATE r36_lab.cas_fixture SET generation=generation+1
      WHERE @SCOPE@ AND generation=expected AND proof=@PROOF@ AND active=true;
    IF NOT FOUND THEN RETURN false; END IF;
    INSERT INTO r36_lab.historical_pin VALUES (@KEYS@,@PAYLOAD@::jsonb);
    RETURN true;
END;
$body$;
SQL;
$admin->beginTransaction();
try {
    $admin->exec(strtr($sql,['@SCOPE@'=>$where,'@PROOF@'=>$proof,'@KEYS@'=>$quoted,'@PAYLOAD@'=>$payload]));
    $admin->exec("ALTER FUNCTION r36_lab.append_exact_r42(bigint) OWNER TO r42_executor;
REVOKE ALL ON FUNCTION r36_lab.append_exact_r42(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION r36_lab.append_exact_r42(bigint) TO r38_appender;");
    $admin->commit();
} finally { if ($admin->inTransaction()) $admin->rollBack(); }
$denied42=static function(Closure $attempt,string $state,string $label)use($check42):void {
    try {$attempt();} catch(PDOException $e) {
        $check42($e->getCode()===$state,$label.' SQLSTATE '.$e->getCode());
        return;
    }
    throw new RuntimeException('R42 unexpectedly allowed '.$label);
};
$call42=static function(PDO $db,int $expected):bool {
    $q=$db->prepare('SELECT r36_lab.append_exact_r42(?::bigint)');
    $q->execute([$expected]);
    return $q->fetchColumn()===true;
};
$check42($admin->query("SELECT NOT rolcanlogin AND NOT rolsuper AND NOT rolbypassrls FROM pg_roles WHERE rolname='r42_executor'")->fetchColumn()===true,'executor has no login or bypass');
$denied42(fn()=>$append($writer,$nextKeys,$next),'42501','direct append revoked');
$denied42(fn()=>$writer->exec('UPDATE r36_lab.cas_fixture SET generation=99'),'42501','direct generation update revoked');
$denied42(fn()=>$writer->exec('SET ROLE r42_executor'),'42501','cannot assume executor role');
$denied42(fn()=>$call42($b,5),'42501','other buyer cannot invoke');
$denied42(fn()=>$call42($c,5),'42501','other tenant cannot invoke');
$check42(!$call42($writer,5) && $generation()===5 && $readPin($a,$nextKeys)===null,'revoked witness denies function');
$admin->exec("UPDATE r36_lab.cas_fixture SET active=true,generation=6");
$check42(!$call42($writer,5) && $generation()===6,'stale generation denied');
// Actual lock contention using independent connection, not sleeps.
$admin->beginTransaction();
try {
    $admin->exec("UPDATE r36_lab.cas_fixture SET active=false,generation=7");
    $writer->exec("SET lock_timeout='500ms'");
    $denied42(fn()=>$call42($writer,6),'55P03','contended function fails closed');
    $check42($readPin($a,$nextKeys)===null,'no effect before revoker commit');
    $admin->commit();
} finally { if ($admin->inTransaction()) $admin->rollBack(); }
$check42(!$call42($writer,7) && $generation()===7,'committed revocation denies current generation');
$admin->exec("UPDATE r36_lab.cas_fixture SET active=true,generation=8");
$check42($call42($writer,8) && $generation()===9,'fixed scoped append atomically committed');
$check42(!$call42($writer,8) && $generation()===9,'stale retry no effect');
$denied42(fn()=>$call42($writer,9),'23505','duplicate rolls back function statement');
$check42($generation()===9,'duplicate cannot advance generation');
$writer=null;
$a=$connect('r36_reader_a','r36_disposable_reader');
$seen=$readPin($a,$nextKeys);
$check42(is_array($seen) && count($seen)===count($next) && array_diff_assoc($next,$seen)===[],'independent exact readback');
$check42($readPin($b,$nextKeys)===null && $readPin($c,$nextKeys)===null,'read isolation remains');
$check42((int)$a->query('SELECT count(*) FROM r36_lab.historical_pin')->fetchColumn()===5,'exactly one new record');
echo "ONEPAGE R42 PG18 ENTRY BOUNDARY PASS: {$r42} synthetic checks; NO-GO.\n";
