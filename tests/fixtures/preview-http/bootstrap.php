<?php
// CI-owned PostgreSQL container only. Executed inside db container via temporary PHP runner? Host PDO uses CI published DB port.
// This file is run by PHP in the app image as a one-shot, before starting HTTP, with owner credentials mounted only for this command.
declare(strict_types=1);
set_exception_handler(function(Throwable $e){fwrite(STDERR,"PREVIEW_HTTP_BOOTSTRAP_DENIED\n");exit(2);});
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('SITES_PREVIEW_HTTP_CI')!=='1')throw new RuntimeException;
$pw=trim(file_get_contents('/run/ci/db-password'));
$db=new PDO('pgsql:host=db;port=5432;dbname=sites_preview_http_ci;sslmode=verify-full;sslrootcert=/run/ci/ca.pem',$user='postgres',$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$a=$db->query("SELECT current_database() AS db, session_user AS login, (SELECT system_identifier::text FROM pg_control_system()) AS cluster")->fetch(PDO::FETCH_ASSOC);
if($a['db']!=='sites_preview_http_ci'||$a['login']!=='postgres'||$a['cluster']!==getenv('PREVIEW_EXPECTED_CLUSTER'))throw new RuntimeException;
$db->exec(file_get_contents('/opt/imagine/contracts/private-preview/001-workspace.sql'));
$principals=json_decode(file_get_contents('/run/ci/preview_principals'),true,12,JSON_THROW_ON_ERROR)['principals'];
foreach(['author','editor','other'] as $n){
 $role='sites_preview_'.$n;$company=$n==='other'?'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb':'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
 $db->exec("CREATE ROLE $role LOGIN PASSWORD ".$db->quote($principals[$n]['sql_password'])." NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; GRANT USAGE ON SCHEMA sites_preview TO $role; GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $role; GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text),sites_preview.review_version(uuid,uuid,integer,text,text) TO $role;");
 if($n!=='editor')$db->exec("GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $role");
 $s=$db->prepare("INSERT INTO sites_preview.principals(database_login,company_id,can_edit,can_review,expires_at) VALUES(?,?::uuid,?::boolean,true,now()+interval '1 hour')");$s->execute([$role,$company,$n==='editor'?'false':'true']);
}
echo "OK: CI PostgreSQL verified, schema and synthetic scoped roles prepared\n";
