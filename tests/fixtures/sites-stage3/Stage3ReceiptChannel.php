<?php
declare(strict_types=1);
namespace SitesStage3Fixture;

use RuntimeException;
use Throwable;

/** Test-only receiver for two inherited, independently supervisor-owned pipes. */
final class Stage3ReceiptChannel
{
    private bool $used=false;

    /**
     * Resources MUST be the two separately inherited read-only anonymous pipes
     * from the independently reviewed supervisor, not paths/stdin/request data.
     * @return array{json:string,signature:string,trustedRunnerKey:string,trustedNonce:string}
     */
    public function receive($control,$envelope):array
    {
        self::need(!$this->used,'REPLAY');
        $this->used=true;
        try {
            $a=is_resource($control)?fstat($control):false;
            $b=is_resource($envelope)?fstat($envelope):false;
            self::need(is_array($a) && is_array($b)
                && ($a['mode']&0170000)===0010000 && ($b['mode']&0170000)===0010000
                && $a['uid']===0 && $b['uid']===0
                && [$a['dev'],$a['ino']]!==[$b['dev'],$b['ino']],'PIPES');
            $deadline=hrtime(true)+5_000_000_000;
            $trust=self::frame($control,96,$deadline);
            $wire=self::frame($envelope,2114,$deadline);
            return self::decode($trust,$wire);
        } catch(Throwable $e) {
            if ($e instanceof RuntimeException && preg_match('/\ASTAGE3_RECEIPT_REFUSED:[A-Z_]{1,20}\z/D',$e->getMessage())===1)
                throw $e;
            throw new RuntimeException('STAGE3_RECEIPT_REFUSED:IO');
        } finally {
            foreach ([$control,$envelope] as $resource) if (is_resource($resource)) fclose($resource);
        }
    }

    private static function frame($pipe,int $max,int $deadline):string
    {
        self::need(stream_set_blocking($pipe,false),'NONBLOCK');
        $value='';
        do {
            $left=$deadline-hrtime(true);
            self::need($left>0,'TIMEOUT');
            $read=[$pipe];$write=null;$except=null;
            self::need(stream_select($read,$write,$except,intdiv($left,1_000_000_000),intdiv($left%1_000_000_000,1000))===1,'TIMEOUT');
            $chunk=fread($pipe,$max+1-strlen($value));
            self::need(is_string($chunk),'PIPE_READ');
            $value.=$chunk;
            self::need(strlen($value)<=$max,'PIPE_BOUND');
        } while (!feof($pipe));
        return $value;
    }

    /** Pure framing decoder: never treats bytes as authenticated without Stage3SupervisedSlots. */
    private static function decode(string $trust,string $wire):array
    {
        self::need(strlen($trust)===96 && strlen($wire)>=67 && strlen($wire)<=2114,'FRAME_SIZE');
        $n=(ord($wire[0])<<8)|ord($wire[1]);
        $nonce=substr($trust,32);
        self::need($n>=1 && $n<=2048 && strlen($wire)===$n+66
            && preg_match('/\A[0-9a-f]{64}\z/D',$nonce)===1,'FRAME_SHAPE');
        return ['json'=>substr($wire,2,$n),'signature'=>substr($wire,2+$n,64),
            'trustedRunnerKey'=>substr($trust,0,32),'trustedNonce'=>$nonce];
    }

    private static function need(bool $condition,string $code):void
    {
        if (!$condition) throw new RuntimeException('STAGE3_RECEIPT_REFUSED:'.$code);
    }
}
