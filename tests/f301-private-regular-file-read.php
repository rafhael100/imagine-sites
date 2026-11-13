<?php

declare(strict_types=1);

/** F1-301: isolated descriptor/inode readback tests. No network or real data. */
require __DIR__.'/../src/Production/SyntheticPrivateRegularFileReadV1.php';

use Imagine\Sites\Production\SyntheticPrivateRegularFileReadV1 as Reader;

$checks=0;
function check301(bool $ok,string $label):void
{
    global $checks;
    if (!$ok) throw new RuntimeException('F1-301 '.$label);
    ++$checks;
}
$dir=sys_get_temp_dir().'/sites-f301-'.bin2hex(random_bytes(10));
if (!mkdir($dir,0700) || is_link($dir)) {
    throw new RuntimeException('Synthetic fixture directory creation denied.');
}
try {
    $file=$dir.'/index.html';
    $text='synthetic-private-regular-file-only';
    file_put_contents($file,$text);
    check301(Reader::read($file,150000)===$text,
        'normal pinned inode read returns identical bytes');
    check301(Reader::read($file,1)===null,'too-small byte bound denied');
    check301(Reader::read($file,0)===null,'invalid zero bound denied');
    check301(Reader::read($file,150001)===null,'unbounded max denied');
    check301(Reader::read($dir.'/absent',150000)===null,
        'missing file denied');

    $link=$dir.'/symlink.html';
    check301(symlink($file,$link),'synthetic symlink fixture');
    check301(Reader::read($link,150000)===null,
        'symlink never followed');
    unlink($link);

    $hard=$dir.'/hardlink.html';
    check301(link($file,$hard),'synthetic hardlink fixture');
    check301(Reader::read($file,150000)===null,
        'original file with multiple links denied');
    check301(Reader::read($hard,150000)===null,
        'hardlink cannot be treated as independent file');
    unlink($hard);
    check301(Reader::read($file,150000)===$text,
        'original becomes verifiable after hardlink removed');

    file_put_contents($file,'');
    check301(Reader::read($file,150000)===null,
        'empty file rejected');
    file_put_contents($file,str_repeat('a',8193));
    check301(Reader::read($file,8192)===null,
        'oversized fixed manifest denied before materializing');
    // F1-302: broaden non-regular and binary boundary regression coverage.
    check301(Reader::read($dir,150000)===null,
        'directory denied without attempting to stream it');
    check301(Reader::read($file."\\0extra",150000)===null,
        'embedded NUL path denied safely');

    $binary="\\x00\\xff\\x80\\x00end";
    file_put_contents($file,$binary);
    check301(Reader::read($file,strlen($binary))===$binary,
        'binary fixture returned byte-for-byte at exact upper bound');
    check301(Reader::read($file,strlen($binary)-1)===null,
        'binary fixture rejected one byte below upper bound');
    check301(Reader::read($file,150000)===$binary,
        'binary content is not modified by readback');
    if (function_exists('posix_mkfifo')) {
        $fifo=$dir.'/pipe';
        check301(posix_mkfifo($fifo,0600),'FIFO fixture created');
        check301(Reader::read($fifo,150000)===null,
            'FIFO rejected by lstat before any potentially blocking open');
        unlink($fifo);
    }
    rename($file,$dir.'/moved.html');
    check301(Reader::read($file,150000)===null,
        'old path denied after atomic rename');
    check301(Reader::read($dir.'/moved.html',150000)===$binary,
        'new regular path accepted after atomic rename');
    rename($dir.'/moved.html',$file);

    file_put_contents($file,$text);
    check301(Reader::read($file,150000)===$text,
        'valid fixture remains readable after adversarial checks');
} finally {
    foreach (['index.html','symlink.html','hardlink.html','moved.html','pipe'] as $name) {
        $path=$dir.'/'.$name;
        if (is_file($path) || is_link($path)) @unlink($path);
    }
    @rmdir($dir);
}
echo "$checks F1-301 bounded private inode readback checks passed (synthetic only).\n";
