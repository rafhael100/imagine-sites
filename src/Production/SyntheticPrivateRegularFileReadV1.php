<?php

declare(strict_types=1);

namespace Imagine\Sites\Production;

/**
 * F1-301: bounded read of a fixed private synthetic fixture file.
 *
 * Bind the opened descriptor to the named regular inode before and after
 * reading. Never follow symlinks or hardlinks. This is defence-in-depth
 * against accidental or adversarial file substitution in CI/local labs;
 * it is not a production object-storage attestation or IAM boundary.
 */
final class SyntheticPrivateRegularFileReadV1
{
    /** @param array<string|int,mixed> $opened @param array<string|int,mixed> $named */
    private static function sameRegularInode(array $opened,array $named):bool
    {
        return ($opened['mode'] & 0170000)===0100000 &&
            ($named['mode'] & 0170000)===0100000 &&
            ($opened['nlink']??0)===1 &&
            ($named['nlink']??0)===1 &&
            $opened['dev']===$named['dev'] &&
            $opened['ino']===$named['ino'] &&
            $opened['size']===$named['size'];
    }

    public static function read(string $path,int $maxBytes):?string
    {
        if ($maxBytes<1 || $maxBytes>150000 || is_link($path)) return null;

        $before=@lstat($path);
        if (!is_array($before) || (($before['mode'] & 0170000)!==0100000) ||
            ($before['nlink']??0)!==1 ||
            $before['size']<1 || $before['size']>$maxBytes) {
            return null;
        }

        $handle=@fopen($path,'rb');
        if (!is_resource($handle)) return null;
        try {
            $opened=@fstat($handle);
            if (!is_array($opened) || !self::sameRegularInode($opened,$before)) {
                return null;
            }
            // Bound the read by the policy, never consume arbitrary bytes
            // from a replaced file or special device.
            $bytes=@stream_get_contents($handle,$maxBytes+1);
            if (!is_string($bytes) || strlen($bytes)!==$opened['size']) {
                return null;
            }
            $afterOpen=@fstat($handle);
            $afterName=@lstat($path);
            if (!is_array($afterOpen) || !is_array($afterName) ||
                is_link($path) ||
                !self::sameRegularInode($afterOpen,$afterName) ||
                !self::sameRegularInode($opened,$afterOpen)) {
                return null;
            }
            return $bytes;
        } finally {
            @fclose($handle);
        }
    }
}
