<?php
declare(strict_types=1);
namespace Stage2Fixture;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Imagine\Sites\Production\PrivatePreviewWorkspace;
use Imagine\Sites\Production\SiteBrief;
use PDO;
use RuntimeException;
use Throwable;

function need(bool $ok, string $phase): void {
    if (!$ok) throw new RuntimeException('STAGE2_FIXTURE_REFUSED:'.$phase);
}

final readonly class Slot {
    private function __construct(public PDO $pdo, public string $database, public string $login, public array $witness) {}
    // Witness must be derived by the disposable cluster supervisor, not from this PDO.
    // Supervisor independently ties its newly created data dir, postmaster and port
    // to the SQL witness, and checks namespace/no outside peer before any base DDL.
    public static function qualify(Connection $connection, array $witness, string $database, string $login): self {
        need(in_array($database, ['portal_sites_stage2_ci','sites_portal_stage2_ci'], true), 'DB_NAME');
        need($database === 'portal_sites_stage2_ci'
            ? in_array($login,['postgres','portal_browser_runtime'],true)
            : in_array($login,['postgres','sites_preview_stage2_author','sites_preview_stage2_reviewer'],true), 'LOGIN');
        need(is_int($witness['port'] ?? null) && $witness['port'] >= 1024 && $witness['port'] <= 65535
            && is_string($witness['dir'] ?? null) && preg_match('~^/tmp/sites-stage2-[a-zA-Z0-9]+/pgdata$~D',$witness['dir'])===1
            && is_string($witness['oid'] ?? null) && ctype_digit($witness['oid']) && $witness['oid']!=='0'
            && is_string($witness['start'] ?? null) && preg_match('/\A[0-9]+(?:[.][0-9]+)?\z/D',$witness['start'])===1
            && is_string($witness['system_id'] ?? null) && preg_match('/\A[1-9][0-9]{10,19}\z/D',$witness['system_id'])===1, 'SUPERVISOR_WITNESS');
        need($connection->getConfig('driver') === 'pgsql' && $connection->getConfig('host') === '127.0.0.1'
            && (string)$connection->getConfig('port') === (string)$witness['port']
            && $connection->getConfig('database') === $database && $connection->getConfig('username') === $login
            && empty($connection->getConfig('url')), 'EFFECTIVE_LARAVEL');
        $pdo=$connection->getPdo();
        $slot=new self($pdo,$database,$login,$witness);
        $slot->assertSql();
        return $slot;
    }

    public function assertSql(): void {
        need(!$this->pdo->inTransaction()
            && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'
            && $this->pdo->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_EXCEPTION
            && $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES)===false, 'PDO');
        $rs=$this->pdo->query(<<<'SQL'
SELECT current_database() AS db,session_user::text AS login,current_user::text AS actor,
       host(inet_server_addr()) AS host,inet_server_port()::text AS port,
       extract(epoch FROM pg_postmaster_start_time())::text AS start,
       current_setting('server_version_num')::int AS version,
       (SELECT oid::text FROM pg_database WHERE datname=current_database()) AS oid,
       (SELECT NOT (rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR rolreplication OR rolinherit)
          AND rolcanlogin AND NOT EXISTS(SELECT 1 FROM pg_auth_members m WHERE m.member=r.oid)
        FROM pg_roles r WHERE rolname=current_user) AS restricted,
       (SELECT rolsuper FROM pg_roles WHERE rolname=current_user) AS superuser,
       pg_is_in_recovery() AS recovering
SQL
        )->fetchAll(PDO::FETCH_ASSOC);
        $r=$rs[0]??[];
        need(count($rs)===1
          && ($r['db']??null)===$this->database && ($r['login']??null)===$this->login && ($r['actor']??null)===$this->login
          && ($r['host']??null)==='127.0.0.1' && (string)($r['port']??'')===(string)$this->witness['port']
          && ($r['start']??null)===$this->witness['start']
          && (string)($r['oid']??'')===$this->witness['oid'] && (int)($r['version']??0)===180007
          && ($r['recovering']??null)===false
          && ($this->login==='postgres' ? ($r['superuser']??null)===true : ($r['restricted']??null)===true), 'EFFECTIVE_SQL');
        if ($this->login==='postgres') {
            $admin=$this->pdo->query("SELECT current_setting('data_directory') AS dir,system_identifier::text AS system_id FROM pg_control_system()")->fetchAll(PDO::FETCH_ASSOC);
            need($admin===[['dir'=>$this->witness['dir'],'system_id'=>$this->witness['system_id']]], 'ADMIN_CLUSTER');
        }
    }
}

function installProjection(Slot $portalAdmin, string $sql): void {
    need($portalAdmin->database==='portal_sites_stage2_ci' && $portalAdmin->login==='postgres', 'DDL_SLOT');
    // Re-read the actual connection before opening the DDL transaction.
    $portalAdmin->assertSql();
    need(hash('sha256',$sql)==='7b1dfdfc26e6def3cf0b326db327b2116e4d99d41299e2da294fe77b0b2edfb0', 'DDL_PIN');
    $p=$portalAdmin->pdo;
    $p->beginTransaction();
    try {
        $p->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='5s'");
        $p->query("SELECT pg_advisory_xact_lock(1190348921)");
        need($p->query("SELECT to_regnamespace('sites_stage2_fixture') IS NULL AND to_regprocedure('public.portal_browser_active(text)') IS NOT NULL")->fetchColumn()===true, 'DDL_PRESTATE');
        $p->exec($sql);
        $p->commit();
    } catch(Throwable $e) {
        if ($p->inTransaction()) $p->rollBack();
        throw new RuntimeException('STAGE2_FIXTURE_REFUSED:DDL');
    }
}

/** This must receive real requests returned from the existing kernel harness.
 * Never construct its session state from request fields or copy it to Sites.
 */
function nativeReference(Request $request): ?string {
    if (!$request->hasSession()) return null;
    $state=$request->session()->get('portal.browser_auth');
    if (!is_array($state) || ($state['phase']??null)!=='business') return null;
    $ref=$state['reference']??null;
    return is_string($ref) && preg_match('/\A[0-9a-f]{64}\z/D',$ref)===1 ? $ref:null;
}

function project(Slot $portal, Request $request): ?array {
    need($portal->database==='portal_sites_stage2_ci' && $portal->login==='portal_browser_runtime', 'PORTAL_SLOT');
    $ref=nativeReference($request);
    if ($ref===null) return null;
    $portal->assertSql();
    $q=$portal->pdo->prepare('SELECT * FROM sites_stage2_fixture.project_session(?)');
    $q->execute([hash('sha256',$ref)]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if ($rows===[]) return null;
    need(count($rows)===1 && count($rows[0])===6 && !in_array(null,$rows[0],true), 'PORTAL_CARDINALITY');
    return $rows[0];
}

function authorize(Slot $portal, Request $request, array $fixtureIdentity): ?array {
    $r=project($portal,$request);
    if ($r===null || $r['subject']!==$fixtureIdentity['subject'] || $r['company']!==$fixtureIdentity['company']) return null;
    return $r;
}

/** Executable case body; setup must obtain current requests via the native Portal
 * client and insert the two explicit fixture identity assignments independently.
 * All PDO slots must be independently qualified; no general authorization API.
 */
function runCases(Slot $portal, Slot $authorDb, Slot $reviewerDb, Slot $readbackDb,
    PortalBrowserAuthentication $auth, Request $pending, Request $author, Request $reviewer, Request $toRevoke,
    array $authorIdentity, array $reviewerIdentity, string $project, SiteBrief $brief): void
{
    need($authorDb->login==='sites_preview_stage2_author' && $reviewerDb->login==='sites_preview_stage2_reviewer'
        && $readbackDb->login==='sites_preview_stage2_reviewer'
        && $authorDb->pdo!==$reviewerDb->pdo && $readbackDb->pdo!==$reviewerDb->pdo
        && count(array_unique(array_map(static fn(Slot $s)=>(int)$s->pdo->query('SELECT pg_backend_pid()')->fetchColumn(),[$portal,$authorDb,$reviewerDb,$readbackDb])))===4
        && $authorDb->witness===$reviewerDb->witness && $readbackDb->witness===$reviewerDb->witness
        && $authorIdentity['subject']!==$reviewerIdentity['subject'] && $authorIdentity['company']===$reviewerIdentity['company'], 'PAIR');
    $company=$authorIdentity['company'];
    $creates=0;
    $create=function(Request $request)use($portal,$authorDb,$authorIdentity,$project,$company,$brief,&$creates):bool{
        $snapshot=authorize($portal,$request,$authorIdentity);
        if ($snapshot===null) return false;
        $authorDb->assertSql();
        if (authorize($portal,$request,$authorIdentity)!==$snapshot) return false;
        ++$creates;
        (new PrivatePreviewWorkspace($authorDb->pdo))->createDemonstration($project,$company,$brief);
        return true;
    };
    need(!$create($pending) && !$create($reviewer) && $creates===0, 'CREATE_ZERO_WRITER');
    need($create($author) && $creates===1, 'AUTHOR_CREATE');

    $reviewerDb->assertSql(); $readbackDb->assertSql();
    $read=new PrivatePreviewWorkspace($readbackDb->pdo);
    $v=$read->view($project,$company,1);
    $digest=$v['sha256'];
    need(is_string($digest) && hash_equals($digest,hash('sha256',$v['html'])), 'DIGEST');
    $count=static function()use($readbackDb,$project,$company,$digest):int{
        $q=$readbackDb->pdo->prepare('SELECT count(*) FROM sites_preview.reviews WHERE project_id=?::uuid AND company_id=?::uuid AND revision=1 AND sha256=?');
        $q->execute([$project,$company,$digest]);
        $value=$q->fetchColumn();
        need((is_int($value)&&$value>=0)||(is_string($value)&&ctype_digit($value)), 'READBACK_COUNT');
        return (int)$value;
    };
    $n=0;
    $review=function(Request $r,int $revision,string $candidate,?callable $before=null)use($portal,$reviewerDb,$reviewerIdentity,$project,$company,$digest,&$n):bool{
        if ($revision!==1 || !hash_equals($digest,$candidate)) return false;
        $first=authorize($portal,$r,$reviewerIdentity);
        if ($first===null) return false;
        if ($before!==null) $before();
        $reviewerDb->assertSql();
        if (authorize($portal,$r,$reviewerIdentity)!==$first) return false;
        ++$n;
        (new PrivatePreviewWorkspace($reviewerDb->pdo))->review($project,$company,1,$digest,'approved_internal');
        return true;
    };
    need($count()===0 && $n===0, 'PREVIEW_UNREVIEWED');
    need(!$review($pending,1,$digest) && !$review($author,1,$digest) && !$review($reviewer,2,$digest)
        && !$review($reviewer,1,str_repeat($digest===str_repeat('0',64)?'1':'0',64)), 'NEGATIVES');
    $ref=nativeReference($toRevoke); $goodRef=nativeReference($reviewer);
    need($ref!==null && $goodRef!==null && !hash_equals($ref,$goodRef)
        && authorize($portal,$toRevoke,$reviewerIdentity)!==null, 'REVOKE_PRESTATE');
    need(!$review($toRevoke,1,$digest,static function()use($auth,$ref):void {
        need($auth->revoke($ref) && !$auth->isActiveBusinessSession($ref), 'NATIVE_REVOKE');
    }) && $n===0 && $count()===0, 'REVOKED_ZERO_WRITER');
    need($review($reviewer,1,$digest) && $n===1 && $count()===1, 'COMMITTED_EXACT_REVIEW');
}
