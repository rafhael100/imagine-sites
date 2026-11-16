<?php
namespace Tests\Unit;
use App\Support\MountedSecret;
use PHPUnit\Framework\TestCase;
final class MountedSecretTest extends TestCase
{
 public function test_absent_mount_setting_preserves_existing_configuration():void
 {
  self::assertSame('existing-config',MountedSecret::read(null,'existing-config'));
  self::assertSame('',MountedSecret::read(''));
 }
 public function test_invalid_paths_and_missing_mount_never_fall_back_or_reveal_private_context():void
 {
  foreach(['/etc/passwd','/run/secrets/../private-key','/run/secrets/nested/private-key','/run/secrets/nonexistent-sites-mount'] as $path){
   try{MountedSecret::read($path,'must-not-be-used');self::fail('Invalid mount accepted');}
   catch(\RuntimeException $e){self::assertSame('Required mounted secret unavailable',$e->getMessage());self::assertStringNotContainsString($path,$e->getMessage());}
  }
 }
}
