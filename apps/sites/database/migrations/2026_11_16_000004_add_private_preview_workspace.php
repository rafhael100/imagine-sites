<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {
  if(DB::getDriverName()!=='pgsql')throw new RuntimeException('PostgreSQL required');
  DB::unprepared(file_get_contents(base_path('../../contracts/private-preview/001-workspace.sql')));
 }
 public function down():void {throw new RuntimeException('Preserve preview history; no destructive rollback');}
};
