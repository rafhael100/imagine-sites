<?php
declare(strict_types=1);
namespace Imagine\Sites\Production;
use PDO;
use RuntimeException;
use Throwable;
/** Persisted demonstration workspace; NEVER an operational order/provisioning authority. */
final class PrivatePreviewWorkspace {
 public function __construct(private PDO $db){}
 public function assertSafeRuntime():void { $this->runtime(); }
 private function runtime():void {
  if($this->db->inTransaction()||$this->db->query('SHOW transaction_isolation')->fetchColumn()!=='read committed')throw new RuntimeException('Preview requires independent current transaction');
  // Check effective privileges on every operation, including inherited/PUBLIC and
  // column grants. An otherwise unprivileged login must not bypass append-only
  // history, the review function or the principal/audit custody boundaries.
  $p=$this->db->query(<<<'SQL'
SELECT r.rolsuper OR r.rolbypassrls OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication
 OR current_user<>session_user
 OR pg_catalog.has_database_privilege(current_user,pg_catalog.current_database(),'CREATE')
 OR EXISTS (
  SELECT 1 FROM pg_catalog.pg_namespace n
  WHERE n.oid='sites_preview'::regnamespace
  AND (pg_catalog.pg_has_role(current_user,n.nspowner,'MEMBER')
   OR pg_catalog.has_schema_privilege(current_user,n.oid,'CREATE,USAGE WITH GRANT OPTION'))
 )
 OR EXISTS (
  SELECT 1 FROM pg_catalog.pg_namespace n WHERE n.nspname='public'
  AND pg_catalog.has_schema_privilege(current_user,n.oid,'CREATE')
 )
 OR EXISTS (
  SELECT 1 FROM pg_catalog.pg_class c
  WHERE c.oid IN ('sites_preview.projects'::regclass,'sites_preview.versions'::regclass,
   'sites_preview.principals'::regclass,'sites_preview.reviews'::regclass,'sites_preview.audit'::regclass)
  AND (pg_catalog.pg_has_role(current_user,c.relowner,'MEMBER')
   OR pg_catalog.has_table_privilege(current_user,c.oid,'UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN')
   OR pg_catalog.has_any_column_privilege(current_user,c.oid,'UPDATE,REFERENCES,SELECT WITH GRANT OPTION,INSERT WITH GRANT OPTION')
   OR CASE WHEN c.relname='principals'
    THEN pg_catalog.has_any_column_privilege(current_user,c.oid,'SELECT,INSERT')
    ELSE NOT(c.relrowsecurity AND c.relforcerowsecurity)
     OR (c.relname IN ('reviews','audit') AND pg_catalog.has_any_column_privilege(current_user,c.oid,'INSERT'))
   END)
 )
 OR EXISTS (
  SELECT 1 FROM pg_catalog.pg_class c WHERE c.oid='sites_preview.audit_id_seq'::regclass
  AND (pg_catalog.pg_has_role(current_user,c.relowner,'MEMBER')
   OR pg_catalog.has_sequence_privilege(current_user,c.oid,'USAGE,SELECT,UPDATE'))
 )
 OR EXISTS (
  SELECT 1 FROM pg_catalog.pg_proc p
  WHERE p.oid IN ('sites_preview.can_access(uuid,text)'::regprocedure,
   'sites_preview.review_version(uuid,uuid,integer,text,text)'::regprocedure,
   'sites_preview.record_change()'::regprocedure,
   'sites_preview.check_writer_identity()'::regprocedure,'sites_preview.immutable()'::regprocedure)
  AND (pg_catalog.pg_has_role(current_user,p.proowner,'MEMBER')
   OR pg_catalog.has_function_privilege(current_user,p.oid,'EXECUTE WITH GRANT OPTION'))
 )
 OR pg_catalog.has_function_privilege(current_user,'sites_preview.record_change()','EXECUTE') AS unsafe
FROM pg_catalog.pg_roles r WHERE r.rolname=current_user
SQL)->fetch(PDO::FETCH_ASSOC);
  if(!$p||$p['unsafe']!==false)throw new RuntimeException('Preview runtime denied');
 }
 public function createDemonstration(string $project,string $company,SiteBrief $brief):array {
  $this->runtime();$this->db->beginTransaction();try{
   $s=$this->db->prepare('INSERT INTO sites_preview.projects(id,company_id,product) VALUES(?::uuid,?::uuid,?)');$s->execute([$project,$company,$brief->productCode]);
   $result=$this->append($project,$company,0,$brief);$this->db->commit();return $result;
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function revise(string $project,string $company,int $expected,SiteBrief $brief):array {
  $this->runtime();$this->db->beginTransaction();try{$result=$this->append($project,$company,$expected,$brief);$this->db->commit();return $result;}
  catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 private function append(string $project,string $company,int $expected,SiteBrief $brief):array {
  $this->db->exec("SET LOCAL statement_timeout='3000ms'");$this->db->exec("SET LOCAL lock_timeout='1500ms'");
  $lock=$this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(?,0))');$lock->execute([$company.'/'.$project]);
  $s=$this->db->prepare('SELECT product FROM sites_preview.projects WHERE id=?::uuid AND company_id=?::uuid');$s->execute([$project,$company]);
  if($s->fetchColumn()!==$brief->productCode)throw new RuntimeException('Preview product/scope denied');
  $s=$this->db->prepare('SELECT coalesce(max(revision),0) FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid');$s->execute([$project,$company]);
  if((int)$s->fetchColumn()!==$expected)throw new PreviewRevisionConflict('Preview revision conflict');
  $artifact=(new StaticSiteRenderer)->render($brief);
  $html=preg_replace('/(<body[^>]*>)/','$1<aside role="note" style="background:#F9941D;color:#222;padding:16px;text-align:center">DEMONSTRAÇÃO PRIVADA — sem contratação, pagamento ou aceite.</aside>',$artifact->html,1);
  $digest=hash('sha256',$html);$revision=$expected+1;
  $s=$this->db->prepare('INSERT INTO sites_preview.versions(project_id,company_id,revision,brief,html,sha256) VALUES(?::uuid,?::uuid,?,?::jsonb,?,?)');
  $s->execute([$project,$company,$revision,json_encode($brief,JSON_THROW_ON_ERROR),$html,$digest]);
  $check=$this->db->prepare("SELECT sites_preview.can_access(?::uuid,'edit')");$check->execute([$company]);if($check->fetchColumn()!==true)throw new RuntimeException('Preview edit revoked');
  return ['project_id'=>$project,'revision'=>$revision,'sha256'=>$digest,'purpose'=>'demonstration_only','publishable'=>false,'client_accepted'=>false];
 }
 public function view(string $project,string $company,int $revision):array {
  $this->runtime();$s=$this->db->prepare('SELECT html,sha256,revision FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid AND revision=?');$s->execute([$project,$company,$revision]);$r=$s->fetch(PDO::FETCH_ASSOC);
  if(!$r||!hash_equals($r['sha256'],hash('sha256',$r['html'])))throw new RuntimeException('Private preview unavailable');return $r;
 }
 public function review(string $project,string $company,int $revision,string $digest,string $decision):void {
  $this->runtime();$this->db->beginTransaction();try{
   $this->db->exec("SET LOCAL statement_timeout='3000ms'");$this->db->exec("SET LOCAL lock_timeout='1500ms'");
   $s=$this->db->prepare('SELECT sites_preview.review_version(?::uuid,?::uuid,?,?,?)');$s->execute([$project,$company,$revision,$digest,$decision]);
   $check=$this->db->prepare("SELECT sites_preview.can_access(?::uuid,'review')");$check->execute([$company]);if($check->fetchColumn()!==true)throw new RuntimeException('Preview review revoked');
   $this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
