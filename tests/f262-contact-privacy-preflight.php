<?php

declare(strict_types=1);

/** F1-262: fictional contact privacy preflight; no PII storage or API. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\Integration\\';
    if(!str_starts_with($class,$prefix))return;
    $part=substr($class,strlen($prefix));
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$part)) {
        throw new RuntimeException('Unexpected F1-262 integration class');
    }
    require __DIR__.'/../src/Integration/'.$part.'.php';
});
use Imagine\Sites\Integration\SyntheticContactPrivacyPreflightV1 as Preflight;

$fixtures=[
    ['email only','Pessoa Exemplo','user@example.invalid',null,true,false,Preflight::READY_UNVERIFIED],
    ['phone only','Pessoa Exemplo',null,'+55 (11) 99999-9999',true,false,Preflight::READY_UNVERIFIED],
    ['both','Pessoa Exemplo','user@example.invalid','11999999999',true,false,Preflight::READY_UNVERIFIED],
    ['consent not indicated','Pessoa Exemplo','user@example.invalid',null,false,false,Preflight::CONSENT_ABSENT],
    ['honeypot set','Pessoa Exemplo','user@example.invalid',null,true,true,Preflight::HONEYPOT_PRESENT],
    ['no name',null,'user@example.invalid',null,true,false,Preflight::MISSING_CONTACT],
    ['no contact','Pessoa Exemplo',null,null,true,false,Preflight::MISSING_CONTACT],
    ['bad phone','Pessoa Exemplo',null,'123',true,false,Preflight::MISSING_CONTACT],
    ['bad email plus phone','Pessoa Exemplo','not-an-email','11999999999',true,false,Preflight::MISSING_CONTACT],
    ['bad email','Pessoa Exemplo','not-an-email',null,true,false,Preflight::MISSING_CONTACT],
    ['name too long',str_repeat('N',121),'user@example.invalid',null,true,false,Preflight::MALFORMED],
    ['phone too long','Pessoa Exemplo',null,str_repeat('5',41),true,false,Preflight::MALFORMED],
    ['control character',"Pessoa\nExemplo",'user@example.invalid',null,true,false,Preflight::MALFORMED],
    ['name remote URL','http://example.invalid','user@example.invalid',null,true,false,Preflight::MALFORMED],
    ['unicode bidi',"Pessoa\u{202E} Exemplo",'user@example.invalid',null,true,false,Preflight::UNICODE_UNSAFE],
    ['unicode zero width',"Pessoa\u{200B} Exemplo",'user@example.invalid',null,true,false,Preflight::UNICODE_UNSAFE],
    ['unicode BOM',"\u{FEFF}Pessoa",'user@example.invalid',null,true,false,Preflight::UNICODE_UNSAFE],
    ['email invisible','Pessoa Exemplo',"user\u{200B}@example.invalid",null,true,false,Preflight::UNICODE_UNSAFE],
    ['invalid UTF8',"\xC3\x28",'user@example.invalid',null,true,false,Preflight::UNICODE_UNSAFE],
    ['honeypot overrides validation',"\xC3\x28",'not-an-email',null,true,true,Preflight::HONEYPOT_PRESENT],
];
$count=0;
foreach ($fixtures as [$label,$name,$email,$phone,$consent,$honeypot,$expected]) {
    $report=Preflight::inspect($name,$email,$phone,$consent,$honeypot);
    if ($report->reasonCode!==$expected ||
        $report->readyForIndependentReview()!==($expected===Preflight::READY_UNVERIFIED) ||
        $report->canCollectPersonalData() ||
        $report->canStorePersonalData() ||
        $report->canDeliverToCrm() ||
        $report->canAcknowledgeVisitor() ||
        $report->canTreatConsentAsVerified()) {
        throw new RuntimeException('F1-262 structural gate mismatch: '.$label);
    }
    $json=json_encode(get_object_vars($report),JSON_THROW_ON_ERROR);
    if(!is_string($json))throw new RuntimeException('F1-262 serialization failed');
    foreach([$name,$email,$phone] as $privateField) {
        if($privateField===null || strlen($privateField)<8)continue;
        if(str_contains($json,$privateField)) {
            throw new RuntimeException('F1-262 report unexpectedly includes sample contact');
        }
    }
    if ($expected===Preflight::CONSENT_ABSENT && !$report->structuralMinimumMet) {
        throw new RuntimeException('F1-262 consent is separate from structural completeness');
    }
    if($expected===Preflight::HONEYPOT_PRESENT &&
        ($report->honeypotClear || $report->consentIntentObserved)) {
        throw new RuntimeException('F1-262 bot-trap must override all review hints');
    }
    ++$count;
}
if($count!==20)throw new RuntimeException('F1-262 fixture count drift');
echo "$count F1-262 privacy/consent-intent preflight examples passed without PII or delivery.\n";
