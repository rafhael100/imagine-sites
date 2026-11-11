<?php
declare(strict_types=1);

/** F1-168: compose metadata HMAC and global nonce in one uncommitted PG18 tx. */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' ||
  getenv('GITHUB_ACTIONS')!=='true' ||
  getenv('SITES_F168_COMPOSITION_LAB')!=='1' ||
  getenv('DB_HOST')!=='127.0.0.1' ||
  getenv('DB_DATABASE')!=='sites_f140_atomic_lab') {
    fwrite(STDERR,"F1-168 requires explicit disposable CI setup.\n");
    exit(1);
}
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Integration\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Unsafe fixture class');
    }
    require __DIR__.'/../src/Integration/'.$name.'.php';
});

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as Draft;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySnapshot as Snapshot;
use Imagine\Sites\Integration\SyntheticLeadBindingAuthoritySource as Source;
use Imagine\Sites\Integration\LabSyntheticCrmRequestCoordinator as Coordinator;
use Imagine\Sites\Integration\LabSyntheticCrmRequestAttemptAssessment as Assessment;
use Imagine\Sites\Integration\SyntheticCrmTechnicalHandoffV1 as Wire;

$count=0;
function check168(bool $ok,string $why):void {
    global $count;
    if (!$ok)throw new RuntimeException('F1-168 '.$why);
    ++$count;
}
function deny168(Assessment $a):bool {
    return !$a->canAuthenticateService() &&
        !$a->canCommitReservation() &&
        !$a->canAcceptLead() &&
        !$a->canSendLeadToCrm() &&
        !$a->canAcknowledgeVisitor() &&
        !$a->canRetryAutomatically() &&
        !$a->canMarkDelivered();
}
function db168(string $user,string $password):PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=sites_f140_atomic_lab',
        $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
}
function stable168(Draft $incoming,bool $active=true,int $first=7,
    ?int $second=null,?string $secondDest=null):Source {
    $firstSnapshot=new Snapshot(
        $incoming->organizationReference,$incoming->bindingReference,
        $incoming->siteReference,$incoming->formReference,
        $incoming->destinationReference,$first,$active
    );
    $secondSnapshot=new Snapshot(
        $incoming->organizationReference,$incoming->bindingReference,
        $incoming->siteReference,$incoming->formReference,
        $secondDest??$incoming->destinationReference,
        $second??$first,$active
    );
    return new class([$firstSnapshot,$secondSnapshot]) implements Source {
        private int $reads=0;
        public function __construct(private readonly array $sequence){}
        public function currentBinding(string $org,string $binding):?Snapshot {
            return $this->sequence[$this->reads++]??null;
        }
    };
}

$admin=db168('postgres','f140-fictional-admin-ci-only');
$app=db168('sites_f167_a','f167-fictional-a-only');
$other=db168('sites_f167_b','f167-fictional-b-only');
$countHistory=static fn():int=>(int)$admin->query(
    'SELECT count(*) FROM f167_lab.synthetic_crm_service_nonce_history'
)->fetchColumn();
$org='11111111-1111-4111-8111-111111111111';
$otherOrg='88888888-8888-4888-8888-888888888888';
$binding='22222222-2222-4222-8222-222222222222';
$sub='33333333-3333-4333-8333-333333333333';
$site='44444444-4444-4444-8444-444444444444';
$form='55555555-5555-4555-8555-555555555555';
$destination='66666666-6666-4666-8666-666666666666';
$make=static fn(string $organization=$org):Draft=>new Draft(
    $organization,$binding,$sub,$site,$form,$destination
);
$envelope=$make();
$now=2000000000;
$nonce1='77777777-7777-4777-8777-777777777777';
$nonce2='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$nonce3='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$attempt=static fn(PDO $db,Draft $envelope,string $nonce,string $operation='deliver',
    ?Source $source=null,?int $issued=null):Assessment=>
    (new Coordinator($db,$source??stable168($envelope)))->prepareWithinTransaction(
        $envelope,$operation,$issued??($now-10),$now+90,$now,$nonce
    );
putenv('SITES_F165_SYNTHETIC_AUTH=1');
putenv('SITES_F166_AUTHORITY_FENCE=1');
putenv('SITES_F167_PG_LAB=1');
putenv('SITES_F168_COMPOSITION_LAB=1');

check168($countHistory()===0,'fresh PG18 nonce ledger starts empty');
$outside=$attempt($app,$envelope,$nonce1);
check168($outside->reasonCode==='TRANSACTION_REQUIRED' &&
    $outside->candidate===null && deny168($outside),
    'no signer or nonce reservation outside explicit transaction');

$app->beginTransaction();
$proposal=$attempt($app,$envelope,$nonce1);
check168($proposal->reasonCode==='LAB_PENDING_TRANSACTION_UNTRUSTED' &&
    $proposal->candidate instanceof Wire && deny168($proposal),
    'stable source plus first nonce only produces uncommitted proposal');
check168($countHistory()===0,
    'separate DB session still sees no committed nonce after preparation');
check168($proposal->candidate->authentication['nonce']===$nonce1 &&
    $proposal->candidate->operation==='deliver',
    'uncommitted candidate is pinned to the same nonce and operation');
$app->commit();
check168($countHistory()===1,
    'caller commit is the only action making nonce history durable');
check168(!$proposal->canMarkDelivered() && !$proposal->canAcknowledgeVisitor(),
    'even committed nonce history never grants CRM ACK');

$app->beginTransaction();
$replay=$attempt($app,$envelope,$nonce1);
check168($replay->reasonCode==='NONCE_REPLAY_DENIED' &&
    $replay->candidate===null && deny168($replay),
    'repeating identical signed metadata cannot re-consume nonce');
$app->commit();
check168($countHistory()===1,'replay causes no database writes');

$app->beginTransaction();
$moved=$attempt($app,$envelope,$nonce2,'deliver',
    stable168($envelope,true,7,8));
check168($moved->reasonCode==='BINDING_OR_WIRE_UNVERIFIED' &&
    $moved->candidate===null && deny168($moved),
    'revision changing between independent-looking reads denies MAC+nonce');
$app->commit();
check168($countHistory()===1,'revoked binding does not consume unrelated nonce');

$app->beginTransaction();
$revoked=$attempt($app,$envelope,$nonce2,'deliver',
    stable168($envelope,false));
check168($revoked->reasonCode==='BINDING_OR_WIRE_UNVERIFIED' &&
    $revoked->candidate===null,
    'inactive binding cannot prepare technical request');
$app->commit();
check168($countHistory()===1,'inactive binding does not consume nonce');

$app->beginTransaction();
$invalidOp=$attempt($app,$envelope,$nonce2,'delete');
check168($invalidOp->reasonCode==='BINDING_OR_WIRE_UNVERIFIED' &&
    $invalidOp->candidate===null,
    'unsupported operation rejected before DB nonce');
$app->commit();

$app->beginTransaction();
$invalidWindow=$attempt($app,$envelope,$nonce2,'deliver',$source=null,$issued=$now+40);
check168($invalidWindow->reasonCode==='BINDING_OR_WIRE_UNVERIFIED',
    'future-dated request rejected before DB mutation');
$app->commit();

$app->beginTransaction();
$second=$attempt($app,$envelope,$nonce2,'lookup');
check168($second->reasonCode==='LAB_PENDING_TRANSACTION_UNTRUSTED' &&
    $second->candidate instanceof Wire && deny168($second),
    'distinct lookup nonce produces a separate candidate with no authority');
$app->rollBack();
check168($countHistory()===1,
    'rollback after candidate production leaves no persistent nonce claim');
$app->beginTransaction();
$afterRollback=$attempt($app,$envelope,$nonce2,'lookup');
check168($afterRollback->reasonCode==='LAB_PENDING_TRANSACTION_UNTRUSTED',
    'rolled-back nonce can be reserved as a genuinely new transaction');
$app->commit();
check168($countHistory()===2,
    'second unique committed nonce remains one durable row');

$app->beginTransaction();
$crossTenant=$attempt($app,$make($otherOrg),$nonce3);
check168($crossTenant->reasonCode==='TENANT_LOGIN_SCOPE_DENIED' &&
    $crossTenant->candidate===null && deny168($crossTenant),
    'app A cannot reserve a request for org B');
$app->commit();
check168($countHistory()===2,
    'cross-company spoof cannot mutate nonce history');

$other->beginTransaction();
$otherTry=$attempt($other,$make($otherOrg),$nonce1,'lookup');
check168($otherTry->reasonCode==='NONCE_REPLAY_DENIED' &&
    $otherTry->candidate===null,
    'a consumed nonce remains globally denied across the second login');
$other->commit();

putenv('SITES_F168_COMPOSITION_LAB');
$app->beginTransaction();
$disabled=$attempt($app,$envelope,$nonce3);
check168($disabled->reasonCode==='LAB_DISABLED' &&
    $disabled->candidate===null && deny168($disabled),
    'composition adapter must be separately switched on');
$app->rollBack();
putenv('SITES_F168_COMPOSITION_LAB=1');

$invalid=false;
try{
    new Assessment('LAB_PENDING_TRANSACTION_UNTRUSTED');
}catch(InvalidArgumentException){$invalid=true;}
check168($invalid,'no uncommitted success-like state without exact candidate');
$invalid=false;
try{
    new Assessment('NONCE_REPLAY_DENIED',$proposal->candidate);
}catch(InvalidArgumentException){$invalid=true;}
check168($invalid,'replay result cannot carry a wire for accidental delivery');

echo "$count F1-168 PG18 binding+HMAC+nonce composition assertions passed. ".
    "No real auth, CRM, lead, durable receipt, visitor ACK, staging or deploy.\n";
