<?php

declare(strict_types=1);

/**
 * F1-365: throwaway PHP built-in server router, loopback-only.
 * This is an HTTP CI fixture, NOT a Laravel route or production API.
 */
function f365Reply(int $httpStatus,array $payload):never
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Imagine-Fixture: F1-365-test-only');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

$remote=(string)($_SERVER['REMOTE_ADDR']??'');
$host=(string)($_SERVER['HTTP_HOST']??'');
if (PHP_SAPI!=='cli-server' || getenv('APP_ENV')!=='testing' ||
    getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F365_HTTP_LAB')!=='1' ||
    $remote!=='127.0.0.1' || $host!=='127.0.0.1:18965') {
    f365Reply(404,['accepted'=>false,'reason'=>'unavailable']);
}
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
$method=(string)($_SERVER['REQUEST_METHOD']??'');
if ($path==='/health' && $method==='GET') {
    f365Reply(200,['status'=>'test-only','ready'=>true]);
}
if ($path!=='/v1/lab/sites/admit') {
    f365Reply(404,['accepted'=>false,'reason'=>'not_found']);
}
if ($method!=='POST') {
    f365Reply(405,['accepted'=>false,'reason'=>'method_not_allowed']);
}
$contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
if ($contentType!=='application/json') {
    f365Reply(415,['accepted'=>false,'reason'=>'content_type']);
}
$length=$_SERVER['CONTENT_LENGTH']??'';
if (!is_string($length) || !ctype_digit($length) ||
    (int)$length < 2 || (int)$length > 8192) {
    f365Reply(413,['accepted'=>false,'reason'=>'request_size']);
}
$input=file_get_contents('php://input',false,null,0,8193);
if (!is_string($input) || strlen($input)!==(int)$length) {
    f365Reply(400,['accepted'=>false,'reason'=>'invalid_body']);
}
// The command is fixed (no shell), the keys are fictional, and the child is
// CLI-only: the parent F1-364 adapter is intentionally not an HTTP class.
$cmd=[PHP_BINARY,__DIR__.'/f365-loopback-worker.php'];
$spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
$proc=proc_open($cmd,$spec,$pipes,null,null,['bypass_shell'=>true]);
if(!is_resource($proc)) {
    f365Reply(503,['accepted'=>false,'reason'=>'fixture_unavailable']);
}
fwrite($pipes[0],$input);
fclose($pipes[0]);
$out=stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr=stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit=proc_close($proc);
if($exit!==0 || !is_string($out) || strlen($out)>2048) {
    // Never send stack traces or database errors to callers.
    f365Reply(503,['accepted'=>false,'reason'=>'fixture_error']);
}
try {
    $result=json_decode($out,true,16,JSON_THROW_ON_ERROR);
} catch(JsonException) {
    f365Reply(503,['accepted'=>false,'reason'=>'fixture_error']);
}
if(!is_array($result) ||
    !in_array($result['kind']??null,['queued','duplicate_ignored','denied','invalid','error'],true)) {
    f365Reply(503,['accepted'=>false,'reason'=>'fixture_error']);
}
$status=match($result['kind']) {
    'queued'=>202,
    'duplicate_ignored'=>200,
    'denied'=>403,
    'invalid'=>400,
    default=>503,
};
f365Reply($status,[
    'accepted'=>in_array($result['kind'],['queued','duplicate_ignored'],true),
    'status'=>$result['kind'],
    'publishable'=>false,
    'customerAcceptance'=>false,
]);
