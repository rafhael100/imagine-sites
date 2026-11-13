<?php

declare(strict_types=1);

/**
 * F1-153: real CRM F3 form-minimum class vs Sites pure synthetic inspector.
 * All values are invented examples; no customer data or network I/O.
 */
spl_autoload_register(static function(string $class):void {
    foreach ([
        'Imagine\\Sites\\Integration\\' => __DIR__.'/../src/Integration/',
        'App\\Core\\Leads\\' => __DIR__.'/../crm-current/app/Core/Leads/',
    ] as $prefix=>$dir) {
        if (!str_starts_with($class,$prefix)) continue;
        $basename=substr($class,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$basename))
            throw new RuntimeException('Unsafe form class name');
        require_once $dir.$basename.'.php';
        return;
    }
});

use Imagine\Sites\Integration\SyntheticSiteContactMinimumInspector as SitesInspector;
use App\Core\Leads\LeadDraftFields as CrmDraft;
use App\Core\Leads\ImagineSiteFormMinimumInspector as CrmInspector;

$checks=0;
function check153(bool $condition,string $description):void {
    global $checks;
    if (!$condition) throw new RuntimeException('F1-153: '.$description);
    ++$checks;
}
function denied153(callable $callback):bool {
    try {$callback();} catch(InvalidArgumentException) {return true;}
    return false;
}
$sites=new SitesInspector();
$crm=new CrmInspector();

$fixtures=[
    ['email contact','Pessoa Exemplo','placeholder@example.invalid',null,true],
    ['telephone contact','Pessoa Exemplo',null,'+55 (11) 99999-9999',true],
    ['both contacts','Pessoa Exemplo','placeholder@example.invalid','11999999999',true],
    ['no contact','Pessoa Exemplo',null,null,false],
    ['no name',null,'placeholder@example.invalid',null,false],
    ['all absent',null,null,null,false],
    ['bad email','Pessoa Exemplo','invalid-at-sign',null,false],
    ['bad phone','Pessoa Exemplo',null,'123',false],
    ['invalid email supplied with valid phone','Pessoa Exemplo','wrong','11999999999',false],
    ['invalid phone supplied with valid email','Pessoa Exemplo','placeholder@example.invalid','123',false],
    ['too many digits','Pessoa Exemplo',null,'+12345678901234567',false],
    ['leading email whitespace','Pessoa Exemplo',' placeholder@example.invalid',null,false],
];
foreach ($fixtures as [$label,$name,$email,$phone,$expected]) {
    $crmReport=$crm->inspect(new CrmDraft($name,$email,$phone));
    $sitesReport=$sites->inspect($name,$email,$phone);
    check153(
        $crmReport->namePresent===$sitesReport->namePresent
        && $crmReport->emailCandidateValid===$sitesReport->emailCandidateValid
        && $crmReport->phoneCandidateValid===$sitesReport->phoneCandidateValid
        && $crmReport->invalidSuppliedContact===$sitesReport->invalidSuppliedContact
        && $crmReport->meetsStructuralMinimum()===$sitesReport->meetsStructuralMinimum()
        && $sitesReport->meetsStructuralMinimum()===$expected,
        'actual CRM and Sites agree on synthetic '.$label
    );
    check153(!$crmReport->canDeliver()
        && !$sitesReport->canCollectPersonalData()
        && !$sitesReport->canStorePersonalData()
        && !$sitesReport->canDeliverToCrm()
        && !$sitesReport->canAcknowledgeVisitor(),
        'no synthetic form result enables PII collection, storage or delivery');
}

$rejected=[
    [null,'',null],
    ['',null,null],
    ['  ',null,null],
    ["Pessoa\nExemplo",'placeholder@example.invalid',null],
    ['Pessoa Exemplo',str_repeat('a',1025),null],
    ['Pessoa Exemplo','placeholder@example.invalid',"1199999\n9999"],
    ['Pessoa Exemplo',null,str_repeat('1',1025)],
];
foreach ($rejected as [$name,$email,$phone]) {
    check153(
        denied153(static fn()=>new CrmDraft($name,$email,$phone))
        && denied153(static fn()=> $sites->inspect($name,$email,$phone)),
        'both reject empty, control or oversized synthetic contact fields'
    );
}
echo "$checks F1-153 real CRM/Sites form minimum checks passed. ".
    "No client data, consent, HTTP intake or CRM receipt.\n";
