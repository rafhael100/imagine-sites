<?php
declare(strict_types=1);

use Imagine\Sites\Production\{OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

// This is a disposable CI database, not an official Portal source or a writer.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted' ||
    getenv('SITES_R36_PG_LAB') !== 'DISPOSABLE_R36_PG18' || getenv('GITHUB_REPOSITORY') !== 'rafhael100/imagine-sites') {
    throw new RuntimeException('R36_DISPOSABLE_SCOPE_REQUIRED');
}
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checks = 0;
$check = static function(bool $ok, string $case) use (&$checks): void {
    if (!$ok) throw new RuntimeException('R36 '.$case);
    ++$checks;
};
// Only a fixed local GitHub service, with literal disposable fixture credentials.
$connect = static function(string $role, string $password): PDO {
    return new PDO('pgsql:host=127.0.0.1;port=55436;dbname=sites_r36_lab;connect_timeout=3',
        $role,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
};
$admin = $connect('postgres','r36_disposable_admin');
$check($admin->query('SELECT current_database()')->fetchColumn()==='sites_r36_lab' &&
    $admin->query("SELECT current_setting('server_version_num')::int BETWEEN 180000 AND 189999")->fetchColumn()===true &&
    $admin->query("SELECT count(*) FROM pg_namespace WHERE nspname='r36_lab'")->fetchColumn()==0,
    'fresh PG18 service');
$admin->exec("CREATE SCHEMA r36_lab;
CREATE TABLE r36_lab.historical_pin (
  tenant_ref text NOT NULL, order_ref text NOT NULL, site_ref text NOT NULL,
  buyer_ref text NOT NULL, acceptance_ref text NOT NULL, payload jsonb NOT NULL,
  PRIMARY KEY (tenant_ref,order_ref,site_ref,buyer_ref,acceptance_ref),
  CHECK (jsonb_typeof(payload)='object')
);
ALTER TABLE r36_lab.historical_pin ENABLE ROW LEVEL SECURITY;
ALTER TABLE r36_lab.historical_pin FORCE ROW LEVEL SECURITY;
REVOKE ALL ON SCHEMA r36_lab FROM PUBLIC;
REVOKE ALL ON r36_lab.historical_pin FROM PUBLIC;");
$tenant = $brief->tenantRef;
$otherTenant = 'fixture-foreign-r36';
$check($tenant!==$otherTenant,'distinct tenants');
$scopes = [
    'r36_reader_a'=>[$tenant,'buyer-one'],
    'r36_reader_b'=>[$tenant,'buyer-two'],
    'r36_reader_c'=>[$otherTenant,'buyer-one'],
];
foreach ($scopes as $role=>$scope) {
    // role names are fixed literals above; fixture selectors are SQL-quoted.
    $admin->exec("CREATE ROLE $role LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS PASSWORD 'r36_disposable_reader'");
    $admin->exec("GRANT USAGE ON SCHEMA r36_lab TO $role; GRANT SELECT ON r36_lab.historical_pin TO $role;");
    $admin->exec("CREATE POLICY $role ON r36_lab.historical_pin FOR SELECT TO $role USING (tenant_ref = ".
        $admin->quote($scope[0])." AND buyer_ref = ".$admin->quote($scope[1]).")");
}

$makePreview = static function(array &$s): OnepageBuyerPrivatePreviewCandidate {
    return new OnepageBuyerPrivatePreviewCandidate(
        static function()use(&$s):array{return $s['record'];},
        static function()use(&$s):array{return $s['head'];},
        static function()use(&$s):array{return $s['confirmation'];},
        static function()use(&$s):array{return $s['review'];}
    );
};
$state = $initial;
$original = $makePreview($state)->observeWithWitness('buyer-one',$brief->tenantRef,
    $brief->orderRef,$brief->siteRef,$brief->sourceVersion);
// The original R35 synthetic pin is captured once and stored as fixture data.
// It is NOT derived from the current preview when the historical reader is called.
$pin = $initial['acceptance'];
unset($pin['approved'],$pin['explicitClick'],$pin['revoked']);
$pin['proofDigest']=$original['proofDigest'];
$pin['renderHandoffSha256']=$original['renderHandoffSha256'];
$selector = static fn(array $p):array=>[$p['tenantRef'],$p['orderRef'],$p['siteRef'],$p['buyerRef'],$p['acceptanceRef']];
$readPin = static function(PDO $db, array $scope):?array {
    $q=$db->prepare('SELECT payload::text FROM r36_lab.historical_pin WHERE tenant_ref=? AND order_ref=? AND site_ref=? AND buyer_ref=? AND acceptance_ref=?');
    $q->execute($scope);
    $rows=$q->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows)===0) return null;
    if (count($rows)!==1) throw new RuntimeException('R36_PIN_AMBIGUOUS');
    $p=json_decode($rows[0],true,16,JSON_THROW_ON_ERROR);
    if (!is_array($p)) throw new RuntimeException('R36_PIN_INVALID');
    return $p;
};
$a=$connect('r36_reader_a','r36_disposable_reader');
$b=$connect('r36_reader_b','r36_disposable_reader');
$c=$connect('r36_reader_c','r36_disposable_reader');
$pids=array_map(static fn(PDO $db)=>$db->query('SELECT pg_backend_pid()')->fetchColumn(),[$admin,$a,$b,$c]);
$check(count(array_unique($pids))===4,'independent connections');
foreach (['r36_reader_a'=>$a,'r36_reader_b'=>$b,'r36_reader_c'=>$c] as $role=>$db) {
    $check($db->query('SELECT current_user')->fetchColumn()===$role,'reader identity');
    $check($db->query("SELECT NOT rolbypassrls AND NOT rolsuper FROM pg_roles WHERE rolname=current_user")->fetchColumn()===true,'reader cannot bypass RLS');
    $check($db->query("SELECT has_table_privilege(current_user,'r36_lab.historical_pin','SELECT') AND NOT has_table_privilege(current_user,'r36_lab.historical_pin','INSERT,UPDATE,DELETE,TRUNCATE')")->fetchColumn()===true,'reader has no mutation privilege');
}
$otherBuyer=array_replace($pin,['buyerRef'=>'buyer-two']);
$foreign=array_replace($pin,['tenantRef'=>$otherTenant]);
$insert=$admin->prepare('INSERT INTO r36_lab.historical_pin VALUES (?,?,?,?,?,?::jsonb)');
$admin->beginTransaction();
foreach ([$pin,$otherBuyer,$foreign] as $p) {
    $insert->execute([...$selector($p),json_encode($p,JSON_THROW_ON_ERROR)]);
}
$check($readPin($a,$selector($pin))===null,'uncommitted pin invisible');
$admin->commit();
$insert=null;
$admin=null; // no writer is available to the gate below.
$loaded=$readPin($a,$selector($pin));
$check(is_array($loaded) && count($loaded)===count($pin) && array_diff_assoc($pin,$loaded)===[],'committed exact record');
$check($readPin($a,$selector($otherBuyer))===null && $readPin($a,$selector($foreign))===null,'reader A RLS isolation');
$check($readPin($b,$selector($pin))===null && $readPin($b,$selector($otherBuyer))!==null,'same-company buyer cannot borrow pin');
$check($readPin($c,$selector($pin))===null && $readPin($c,$selector($foreign))!==null,'foreign tenant cannot borrow pin');
foreach ([$a,$b,$c] as $db) {
    $check((int)$db->query('SELECT count(*) FROM r36_lab.historical_pin')->fetchColumn()===1,'RLS even without selector');
}
foreach ([1,2,4] as $index) {
    $missing=$selector($pin);$missing[$index]='missing-r36';
    $check($readPin($a,$missing)===null,'no fallback for missing selector');
}
$a=null; // independent read after reconnect, not a cached in-process pin.
$a=$connect('r36_reader_a','r36_disposable_reader');
$check($readPin($a,$selector($pin))===$loaded,'durable read after reconnect');
$reads=0;$presentations=0;
$gate=new OnepageExactFinalAcceptanceReadCandidate(
    $makePreview($state),
    static function()use(&$state):array{return $state['release'];},
    static function()use(&$state):array{return $state['acceptance'];},
    static function()use(&$state,&$presentations):array{++$presentations;return $state['presentation'];},
    static function(string ...$keys)use($a,$readPin,&$reads):?array{++$reads;return $readPin($a,$keys);}
);
$args=['buyer-one',$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion];
$seen=$gate->observeHistoricallyBound(...$args);
$check($seen['clientFinalAccepted']===true && $seen['htmlSha256']===$originalArtifact->digest &&
    $seen['canPublish']===false && $seen['publicationAuthorized']===false && $reads===2 && $presentations===2,
    'gate consumes stored pin without publish authority');
$deny=static function(string $case)use($gate,$args,$check,&$reads,&$presentations):void{
    $reads=0;$presentations=0;$denied=false;
    try{$gate->observeHistoricallyBound(...$args);}catch(RuntimeException $e){
        $denied=$e->getMessage()==='ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' && $e->getPrevious()===null;
    }
    $check($denied && $reads===1 && $presentations===0,$case);
};
$state['confirmation']['confirmationRef']='rights-rotated-r36';
$state['review']['buyerConfirmationRef']='rights-rotated-r36';
$state['review']['approvalRef']='review-rotated-r36';
$deny('rotated rights cannot rewrite historical record');
$state=$initial;
$changedPlan=json_decode($state['record']['plan'],true,16,JSON_THROW_ON_ERROR);
$changedPlan['operationId']='op-render-r36-replaced';
$state['record']['plan']=$json($changedPlan);
$deny('changed plan cannot rewrite historical record');
$check($readPin($a,$selector($pin))===$loaded,'historical record unchanged after denials');
echo "ONEPAGE R36 PG18 PIN READBACK PASS: {$checks} synthetic checks; NO-GO.\n";
