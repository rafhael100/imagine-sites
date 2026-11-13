<?php

declare(strict_types=1);

/** F1-300: ephemeral standalone flock tests. No Laravel, network or client data. */
require __DIR__.'/../src/Production/SyntheticPrivateBundleLockV1.php';

use Imagine\Sites\Production\SyntheticPrivateBundleLockV1 as Lock;

$checks=0;
function check300(bool $condition,string $label):void
{
    global $checks;
    if (!$condition) throw new RuntimeException('F1-300 '.$label);
    ++$checks;
}
$dir=sys_get_temp_dir().'/sites-f300-'.bin2hex(random_bytes(10));
if (!mkdir($dir,0700) || is_link($dir)) {
    throw new RuntimeException('F1-300 isolated fixture setup failed');
}
try {
    check300(Lock::acquire($dir,'../../unsafe',true)===null,
        'product path traversal refused');
    check300(Lock::acquire($dir,'onepage',false)===null,
        'reader cannot create a missing writer lock');
    check300(!file_exists($dir.'/.sites-f1-24-onepage.lock'),
        'read-only verification leaves no lock-file side effect');
    $writer=Lock::acquire($dir,'onepage',true);
    check300($writer!==null,'first per-product writer lock acquired');
    check300(Lock::acquire($dir,'onepage',true)===null,
        'parallel writer denied without waiting');
    check300(Lock::acquire($dir,'onepage',false)===null,
        'parallel readback denied without waiting');
    $other=Lock::acquire($dir,'landing_page',true);
    check300($other!==null,'independent landing product lock permitted');
    $other?->release();
    $writer?->release();
    $writer?->release();
    $reader=Lock::acquire($dir,'onepage',false);
    check300($reader!==null,'reader allowed after writer releases lock');
    $reader?->release();
    $again=Lock::acquire($dir,'onepage',true);
    check300($again!==null,'reacquiring persistent inode succeeds');
    $again?->release();

    $target=$dir.'/canary.txt';
    file_put_contents($target,'unchanged');
    $unsafe=$dir.'/.sites-f1-24-onepage.lock';
    unlink($unsafe);
    check300(symlink($target,$unsafe),'fixture symlink created');
    check300(Lock::acquire($dir,'onepage',true)===null,
        'symlink cannot be used as a lock path');
    check300(file_get_contents($target)==='unchanged',
        'no followed symlink write occurred');
    unlink($unsafe);
    unlink($target);

    check300(Lock::acquire($dir.chr(0).'suffix','onepage',true)===null,
        'NUL in private directory path is denied before filesystem calls');

    file_put_contents($unsafe,'untrusted data');
    check300(Lock::acquire($dir,'onepage',true)===null,
        'nonempty existing writer lock is denied without truncation');
    check300(file_get_contents($unsafe)==='untrusted data',
        'failed acquisition leaves foreign lock bytes unchanged');
    unlink($unsafe);

    file_put_contents($unsafe,'');
    chmod($unsafe,0644);
    check300(Lock::acquire($dir,'onepage',false)===null,
        'read-only verifier denies a world-readable lock file');
    $repaired=Lock::acquire($dir,'onepage',true);
    check300($repaired!==null,'writer can secure an otherwise valid empty lock');
    check300((fileperms($unsafe) & 0777)===0600,
        'writer acquires an exclusively private 0600 inode');
    $repaired?->release();

    $hard=$dir.'/extra-lock-hardlink';
    check300(link($unsafe,$hard),'hardlink fixture created');
    check300(Lock::acquire($dir,'onepage',true)===null,
        'writer rejects lock inode with multiple hardlinks');
    check300(Lock::acquire($dir,'onepage',false)===null,
        'verifier rejects lock inode with multiple hardlinks');
    unlink($hard);
    check300(Lock::acquire($dir,'onepage',false)!==null,
        'secure lock can be acquired after unsafe hardlink removal');
} finally {
    foreach (['onepage','landing_page'] as $product) {
        $path=$dir.'/.sites-f1-24-'.$product.'.lock';
        if (is_file($path) || is_link($path)) @unlink($path);
    }
    @unlink($dir.'/canary.txt');
    @unlink($dir.'/extra-lock-hardlink');
    @rmdir($dir);
}
echo "$checks F1-300 private bundle interprocess locks passed (synthetic only).\n";
