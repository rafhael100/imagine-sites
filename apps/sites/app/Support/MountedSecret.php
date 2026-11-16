<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;
/** Read a deployment-owned file mount. Never log path, contents or fall back on failure. */
final class MountedSecret
{
 public static function read(?string $path,string $fallback=''):string
 {
  if($path===null||$path==='')return $fallback;
  if(preg_match('/\A\/run\/secrets\/[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D',$path)!==1||is_link($path)||!is_file($path)||!is_readable($path))throw new RuntimeException('Required mounted secret unavailable');
  $size=filesize($path);$mode=fileperms($path);
  if($size===false||$size<16||$size>4096||$mode===false||($mode&0022)!==0)throw new RuntimeException('Mounted secret policy denied');
  $raw=file_get_contents($path);
  if(!is_string($raw))throw new RuntimeException('Required mounted secret unavailable');
  $value=rtrim($raw,"\r\n");
  if(strlen($value)<16||str_contains($value,"\n")||str_contains($value,"\r")||str_contains($value,"\0"))throw new RuntimeException('Mounted secret policy denied');
  return $value;
 }
}
