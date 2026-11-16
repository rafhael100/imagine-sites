<?php
declare(strict_types=1);
if(getenv('CI')!=='true'||getenv('SITES_PRIVATE_PREVIEW_PG_CI')!=='1')exit(1);
foreach(['SiteBrief','ReleaseArtifact','StaticSiteRenderer','PrivatePreviewWorkspace'] as $c)require __DIR__.'/../src/Production/'.$c.'.php';
use Imagine\Sites\Production\PrivatePreviewWorkspace as W;
use Imagine\Sites\Production\SiteBrief as B;
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_preview_ci';$owner=new PDO($dsn,'postgres',getenv('PGPASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$actual=$owner->query("SELECT current_database() AS db,session_user AS login,host(inet_server_addr()) AS addr,inet_server_port() AS port,(SELECT system_identifier::text FROM pg_control_system()) AS cluster")->fetch(PDO::FETCH_ASSOC);
if(!$actual||$actual['db']!=='sites_preview_ci'||$actual['login']!=='postgres'||$actual['addr']!==getenv('SITES_PREVIEW_EXPECTED_DB_ADDR')||(int)$actual['port']!==5432||$actual['cluster']!==getenv('SITES_PREVIEW_EXPECTED_CLUSTER')){fwrite(STDERR,"PREVIEW_DISPOSABLE_DATABASE_DENIED ".json_encode(['db'=>$actual['db']==='sites_preview_ci','login'=>$actual['login']==='postgres','address'=>$actual['addr']===getenv('SITES_PREVIEW_EXPECTED_DB_ADDR'),'port'=>(int)$actual['port']===5432,'cluster'=>$actual['cluster']===getenv('SITES_PREVIEW_EXPECTED_CLUSTER')])."\n");exit(2);}
$owner->exec(file_get_contents(__DIR__.'/../contracts/private-preview/001-workspace.sql'));
$company='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';$other='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';$pw=bin2hex(random_bytes(32));
foreach(['preview_author','preview_editor','preview_other'] as $role){
 $owner->exec("CREATE ROLE $role LOGIN PASSWORD '$pw' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; GRANT USAGE ON SCHEMA sites_preview TO $role; GRANT SELECT ON sites_preview.projects,sites_preview.versions,sites_preview.reviews,sites_preview.audit TO $role; GRANT INSERT ON sites_preview.projects,sites_preview.versions TO $role; GRANT EXECUTE ON FUNCTION sites_preview.can_access(uuid,text),sites_preview.review_version(uuid,uuid,integer,text,text) TO $role;");
}
$owner->exec("INSERT INTO sites_preview.principals VALUES('preview_author','$company',true,true,now()+interval '1 hour',NULL),('preview_editor','$company',false,true,now()+interval '1 hour',NULL),('preview_other','$other',true,true,now()+interval '1 hour',NULL)");
$connect=fn($role)=>new PDO($dsn,$role,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db=$connect('preview_author');$w=new W($db);$editor=new W($connect('preview_editor'));$cross=new W($connect('preview_other'));
$n=0;$ok=function($v)use(&$n){if(!$v)throw new RuntimeException('Preview assertion '.($n+1));$n++;};$deny=function($f)use($ok){try{$f();}catch(Throwable){$ok(true);return;}$ok(false);};
foreach(['onepage','landing_page'] as $i=>$product){
 $project=$i?'22222222-2222-4222-8222-222222222222':'11111111-1111-4111-8111-111111111111';
 $brief=new B($product,'Empresa Demonstração','Uma presença profissional','Conteúdo fictício para revisão privada.','Saiba mais',['Benefício demonstrativo']);
 $v=$w->createDemonstration($project,$company,$brief);$ok($v['purpose']==='demonstration_only'&&$v['publishable']===false&&$v['client_accepted']===false);
 $html=$w->view($project,$company,1);$ok(hash('sha256',$html['html'])===$v['sha256']);$ok(str_contains($html['html'],'DEMONSTRAÇÃO PRIVADA'));
 $deny(fn()=>$cross->view($project,$company,1));$deny(fn()=>$w->review($project,$company,1,$v['sha256'],'approved_internal'));
 $editor->review($project,$company,1,$v['sha256'],'approved_internal');$ok((int)$db->query("SELECT count(*) FROM sites_preview.reviews WHERE project_id='$project'")->fetchColumn()===1);
 $next=$w->revise($project,$company,1,new B($product,'Empresa Demonstração','Conteúdo revisado','Outro conteúdo fictício para revisão.','Saiba mais',['Benefício revisado']));$ok($next['revision']===2&&$next['sha256']!==$v['sha256']);
 $deny(fn()=>$w->revise($project,$company,1,$brief));$deny(fn()=>$editor->review($project,$company,2,$v['sha256'],'approved_internal'));
 $ok((new W($connect('preview_author')))->view($project,$company,2)['sha256']===$next['sha256']);
 if(getenv('SITES_PREVIEW_EVIDENCE_DIR'))file_put_contents(getenv('SITES_PREVIEW_EVIDENCE_DIR').'/'.$product.'.html',$html['html']);
}
$deny(fn()=>$db->exec("UPDATE sites_preview.projects SET purpose='operational_order'"));
$deny(fn()=>$db->exec('DELETE FROM sites_preview.versions'));
$deny(fn()=>$db->exec("UPDATE sites_preview.principals SET can_edit=true"));
$ok((int)$db->query('SELECT count(*) FROM sites_preview.audit')->fetchColumn()===8);
$owner->exec("UPDATE sites_preview.principals SET revoked_at=now() WHERE database_login='preview_author'");$deny(fn()=>$w->view('11111111-1111-4111-8111-111111111111',$company,2));
$deny(fn()=>(new W($owner))->view('11111111-1111-4111-8111-111111111111',$company,2));
echo "OK: $n private persisted demonstration, revision, editorial separation, hash and cross-tenant assertions; no order/payment/acceptance authority\n";
