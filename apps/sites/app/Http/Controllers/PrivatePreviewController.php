<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Preview\PreviewPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Imagine\Sites\Production\PrivatePreviewWorkspace;
use Imagine\Sites\Production\SiteBrief;
use Throwable;
final class PrivatePreviewController {
 public function handle(Request $request,?string $project=null,?string $revision=null,?string $action=null) {
  try {$principal=PreviewPrincipal::fromRequest($request);$db=$principal->db;$company=$principal->company;$workspace=new PrivatePreviewWorkspace($db);$workspace->assertSafeRuntime();
   if($request->isMethod('post')) {
    if(strlen($request->getContent())>16384)return $this->secure(response('Formulário excede o limite.',413));
    $allowed=['_token','product','brand','headline','description','call_to_action','benefits','expected_revision','decision','sha256'];
    if(array_diff(array_keys($request->all()),$allowed))return $this->secure(response('Campos não permitidos.',422));
    if($action==='review') {
     $principal->requireCapability('review');$input=$request->validate(['sha256'=>'required|regex:/\A[a-f0-9]{64}\z/D','decision'=>'required|in:approved_internal,changes_requested']);
     $workspace->review($project,$company,(int)$revision,$input['sha256'],$input['decision']);
     return $this->secure(redirect('/_preview/projects/'.$project));
    }
    $principal->requireCapability('edit');
    $v=$request->validate(['product'=>'required|in:onepage,landing_page','brand'=>'required|string|max:120','headline'=>'required|string|max:180','description'=>'required|string|max:600','call_to_action'=>'required|string|max:80','benefits'=>'required|string|max:1500','expected_revision'=>'sometimes|integer|min:1|max:10000']);
    $benefits=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',$v['benefits'])),fn($x)=>$x!==''));
    $brief=new SiteBrief($v['product'],$v['brand'],$v['headline'],$v['description'],$v['call_to_action'],$benefits);
    if($project===null){$project=(string)Str::uuid();$workspace->createDemonstration($project,$company,$brief);}
    else{$workspace->revise($project,$company,(int)($v['expected_revision']??0),$brief);}
    return $this->secure(redirect('/_preview/projects/'.$project));
   }
   if($action==='html') {
    $r=$workspace->view($project,$company,(int)$revision);$principal->requireCapability('read');
    return $this->secure(response($r['html'],200,['Content-Type'=>'text/html; charset=UTF-8','Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; sandbox; frame-ancestors 'none'; base-uri 'none'; form-action 'none'"]));
   }
   $s=$db->prepare('SELECT id,product FROM sites_preview.projects WHERE company_id=?::uuid ORDER BY created_at DESC LIMIT 50');$s->execute([$company]);$projects=$s->fetchAll(\PDO::FETCH_ASSOC);
   $versions=[];$reviews=[];$brief=null;
   if($project!==null){
    $s=$db->prepare('SELECT revision,sha256,brief,created_at FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid ORDER BY revision DESC LIMIT 100');$s->execute([$project,$company]);$versions=$s->fetchAll(\PDO::FETCH_ASSOC);if(!$versions)return $this->secure(response('Prévia indisponível.',404));
    $brief=json_decode($versions[0]['brief'],true,12,JSON_THROW_ON_ERROR);
    $s=$db->prepare('SELECT revision,decision,created_at FROM sites_preview.reviews WHERE project_id=?::uuid AND company_id=?::uuid ORDER BY revision DESC LIMIT 100');$s->execute([$project,$company]);$reviews=$s->fetchAll(\PDO::FETCH_ASSOC);
   }
   $principal->requireCapability('read');
   return $this->secure(response()->view('preview.workspace',compact('projects','project','versions','reviews','brief')));
  }catch(\Illuminate\Validation\ValidationException $e){return $this->secure(response('Revise os campos do briefing.',422));}
  catch(Throwable){return $this->secure(response('Workspace indisponível ou operação não autorizada. Recarregue antes de tentar novamente.',403));}
 }
 private function secure($response){return $response->withHeaders(['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow','X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'DENY','Referrer-Policy'=>'no-referrer','Content-Security-Policy'=>$response->headers->get('Content-Security-Policy')?:"default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"]);}
}
