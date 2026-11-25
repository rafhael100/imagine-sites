<?php
declare(strict_types=1);

use Imagine\Sites\Production\{LocalSiteRendering, OnepageExactFinalAcceptanceReadCandidate};

// Same committed restricted CAS record, not a reconstructed pin.
require __DIR__.'/onepage-cas-entry-r42.php';
require_once __DIR__.'/fixtures/onepage-r04-loopback.php';
$r44=0;
$verify44=static function(bool $ok,string $case)use(&$r44):void {
    if (!$ok) throw new RuntimeException('R44 '.$case);
    ++$r44;
};
$state44=$initial;
$state44['acceptance']['acceptanceRef']=$next['acceptanceRef'];
$args44=['buyer-one',$brief->tenantRef,$brief->orderRef,$brief->siteRef,$brief->sourceVersion];
$copy44=json_decode($editorial,true,16,JSON_THROW_ON_ERROR);
$copy44['headline']='Redação fictícia R44 atualizada na origem privada';
$v2=LocalSiteRendering::render($json($copy44));
$verify44($v2->digest!==$originalArtifact->digest,'independent renderer v2');
$admin=null;
withOnepageR04Loopback($originalArtifact->html, static function(Closure $http,string $directory)use(
    &$state44,$makePreview,$readPin,$a,$b,$c,$args44,$originalArtifact,$v2,$verify44,$nextKeys,$seen
):void {
    $ready=false;
    for($i=0;$i<40;++$i){
        if($http('/private-onepage',null)['status']===403){$ready=true;break;}
        usleep(50000);
    }
    $verify44($ready,'fixture origin ready');
    $pinReads=0;$gets=0;
    $make=static function(PDO $db,?string $session,?Closure $after=null)use(
        &$state44,$makePreview,$readPin,$http,&$pinReads,&$gets
    ):OnepageExactFinalAcceptanceReadCandidate {
        return new OnepageExactFinalAcceptanceReadCandidate(
            $makePreview($state44),
            static function()use(&$state44):array{return $state44['release'];},
            static function()use(&$state44):array{return $state44['acceptance'];},
            static function()use(&$state44,$http,$session,$after,&$gets):array {
                ++$gets;
                $response=$http('/private-onepage',$session);
                if($response['status']!==200 || !$response['noStore'] || !$response['html'] ||
                    $response['bytes']==='' || strlen($response['bytes'])>262144) {
                    throw new RuntimeException('R44_HTTP_DENIED');
                }
                if($after!==null)$after($gets);
                return array_replace($state44['presentation'],['servedHtmlBytes'=>$response['bytes']]);
            },
            static function(string ...$keys)use($db,$readPin,&$pinReads):?array {
                ++$pinReads;return $readPin($db,$keys);
            }
        );
    };
    $owner='synthetic-buyer-one-session';
    $pass=static function()use($make,$a,$owner,$args44,$verify44,$originalArtifact,&$pinReads,&$gets):void {
        $pinReads=0;$gets=0;
        $result=$make($a,$owner)->observeHistoricallyBound(...$args44);
        $verify44($result['clientFinalAccepted']===true && $result['htmlSha256']===$originalArtifact->digest &&
            $result['canPublish']===false && $result['publicationAuthorized']===false &&
            $pinReads===2 && $gets===2,'actual SQL pin and two HTTP observations');
    };
    $deny=static function(OnepageExactFinalAcceptanceReadCandidate $gate,int $pins,int $httpReads,string $case)use(
        $args44,$verify44,&$pinReads,&$gets
    ):void {
        $pinReads=0;$gets=0;$denied=false;
        ob_start();
        try{$gate->observeHistoricallyBound(...$args44);}
        catch(RuntimeException $e){$denied=$e->getMessage()==='ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED' && $e->getPrevious()===null;}
        finally{$output=ob_get_clean();}
        $verify44($denied && $output==='' && $pinReads===$pins && $gets===$httpReads,$case);
    };
    $pass();
    $deny($make($b,$owner),1,0,'other SQL buyer cannot trigger HTTP read');
    $deny($make($c,$owner),1,0,'foreign tenant cannot trigger HTTP read');
    $deny($make($a,null),1,1,'missing HTTP fixture session denied despite durable pin');
    $deny($make($a,'synthetic-buyer-two-session'),1,1,'other HTTP fixture session denied despite durable pin');
    // Keep receipt, pin and release metadata unchanged; switch only the real
    // bytes served by the independent local origin.
    $deny($make($a,$owner,static function(int $read)use($directory,$v2):void {
        if($read===1 && file_put_contents($directory.'/preview.html',$v2->html,LOCK_EX)!==strlen($v2->html))
            throw new RuntimeException('R44_FIXTURE_WRITE_FAILED');
    }),2,2,'origin changes after first GET while pin remains immutable');
    $deny($make($a,$owner),1,1,'stale pin cannot validate changed HTTP bytes');
    if(file_put_contents($directory.'/preview.html',$originalArtifact->html,LOCK_EX)!==strlen($originalArtifact->html))
        throw new RuntimeException('R44_FIXTURE_RESTORE_FAILED');
    $pass();
    $verify44($readPin($a,$nextKeys)===$seen &&
        (int)$a->query('SELECT count(*) FROM r36_lab.historical_pin')->fetchColumn()===5,
        'HTTP observations never modify committed history');
});
echo "ONEPAGE R44 CAS AND HTTP READBACK PASS: {$r44} synthetic checks; NO-GO.\n";
