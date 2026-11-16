<?php
declare(strict_types=1);
namespace App\Preview;
use Illuminate\Http\Request;
use PDO;
use RuntimeException;
/** Nominal demonstration access only. Never a Portal contract or customer authority. */
final readonly class PreviewPrincipal {
 public function __construct(public PDO $db,public string $company,public string $name){}
 public static function fromRequest(Request $request):self {
  if(config('private_preview.enabled')!==true||!app()->environment('staging')||config('app.debug')!==false||
   config('session.driver')!=='cookie'||config('session.secure')!==true||config('session.http_only')!==true||config('session.same_site')!=='strict'||
   $request->server('IMAGINE_PREVIEW_AUTHENTICATED')!=='nginx-basic-v1'||$request->server('HTTPS')!=='on')throw new RuntimeException('Preview access unavailable');
  $name=$request->server('REMOTE_USER');
  if(!is_string($name)||!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{1,63}\z/D',$name))throw new RuntimeException('Preview access unavailable');
  $path=config('private_preview.principals_file');
  if($path!=='/run/secrets/preview_principals'||is_link($path)||!is_file($path)||!is_readable($path)||filesize($path)>16384||!in_array(fileperms($path)&0777,[0400,0600],true))throw new RuntimeException('Preview access unavailable');
  $data=json_decode(file_get_contents($path),true,12,JSON_THROW_ON_ERROR);
  if(($data['schema']??null)!=='SITES_DEMO_PRINCIPALS_V1')throw new RuntimeException('Preview access unavailable');
  $all=$data['principals']??null;
  if(!is_array($all)||count($all)>32)throw new RuntimeException('Preview access unavailable');
  $logins=[];foreach($all as $entry){if(!is_array($entry)||!is_string($entry['sql_login']??null)||in_array($entry['sql_login'],$logins,true))throw new RuntimeException('Preview principal reuse denied');$logins[]=$entry['sql_login'];}
  $p=$all[$name]??null;
  if(!is_array($p)||!is_int($p['expires_at']??null)||$p['expires_at']<=time()||($p['revoked']??true)!==false||
   !is_string($p['company_id']??null)||!preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',$p['company_id'])||
   !is_string($p['sql_login']??null)||!preg_match('/\Asites_preview_[a-z0-9_]{1,48}\z/D',$p['sql_login'])||
   !is_string($p['sql_password']??null)||strlen($p['sql_password'])<32||strlen($p['sql_password'])>256)throw new RuntimeException('Preview access unavailable');
  $c=config('database.connections.pgsql');
  foreach(['host','database','port','sslrootcert'] as $key)if(!is_string($c[$key]??null)||!preg_match('/\A[A-Za-z0-9_\/.:-]{1,200}\z/D',$c[$key]))throw new RuntimeException('Preview database unavailable');
  if(($c['sslmode']??null)!=='verify-full'||!empty($c['url'])||!is_readable($c['sslrootcert']))throw new RuntimeException('Preview database unavailable');
  $dsn='pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'].';sslmode=verify-full;sslrootcert='.$c['sslrootcert'].';connect_timeout=3';
  $db=new PDO($dsn,$p['sql_login'],$p['sql_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false]);
  $db->exec("SET statement_timeout='3000ms'; SET lock_timeout='1500ms'");
  $tls=$db->query('SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()')->fetchColumn();
  if($tls!==true||$db->query('SELECT session_user')->fetchColumn()!==$p['sql_login'])throw new RuntimeException('Preview database unavailable');
  $result=new self($db,$p['company_id'],$name);$result->requireCapability('read');return $result;
 }
 public function requireCapability(string $capability):void {
  $s=$this->db->prepare('SELECT sites_preview.can_access(?::uuid,?)');$s->execute([$this->company,$capability]);
  if($s->fetchColumn()!==true)throw new RuntimeException('Preview permission unavailable');
 }
}
