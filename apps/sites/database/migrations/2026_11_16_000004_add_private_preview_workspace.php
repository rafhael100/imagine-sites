<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {
  if(DB::getDriverName()!=='pgsql')throw new RuntimeException('PostgreSQL required');
  DB::unprepared(file_get_contents(base_path('../../contracts/private-preview/001-workspace.sql')));
 }
 public function down():void {
  if(DB::getDriverName()!=='pgsql')throw new RuntimeException('PostgreSQL required');
  DB::transaction(function(){
   // FORCE RLS can hide rows even from owners: only an already privileged migration connection may prove emptiness.
   $role=DB::selectOne('SELECT rolsuper OR rolbypassrls AS can_inspect FROM pg_roles WHERE rolname=current_user');
   if(!$role||!$role->can_inspect)throw new RuntimeException('Cannot prove preview namespace empty');
   foreach(['principals','projects','versions','reviews','audit'] as $table){
    if(DB::table('sites_preview.'.$table)->exists())throw new RuntimeException('Preserve populated preview history; restore review required');
   }
   foreach(['reviews','versions','projects','audit','principals'] as $table)DB::statement('DROP TABLE sites_preview.'.$table);
   foreach(['review_version(uuid,uuid,integer,text,text)','can_access(uuid,text)','check_writer_identity()','immutable()','record_change()'] as $function)DB::statement('DROP FUNCTION sites_preview.'.$function);
   DB::statement('DROP SCHEMA sites_preview'); // No CASCADE; unknown dependencies abort the entire transaction.
  });
 }
};
