<?php

declare(strict_types=1);

/** F1-151: CI-only runtime compatibility of real Portal F133 and Sites v1. */
spl_autoload_register(static function(string $class):void {
    foreach ([
        'Imagine\\Sites\\Integration\\' => __DIR__.'/../src/Integration/',
        'App\\Domain\\SiteIntake\\' => __DIR__.'/../portal-current/apps/portal/app/Domain/SiteIntake/',
        'App\\Domain\\ServicePortfolio\\' => __DIR__.'/../portal-current/apps/portal/app/Domain/ServicePortfolio/',
    ] as $prefix=>$path) {
        if (!str_starts_with($class,$prefix)) continue;
        $basename=substr($class,strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$basename)) {
            throw new RuntimeException('Unsafe Portal/Sites class path');
        }
        require_once $path.$basename.'.php';
        return;
    }
});

use Imagine\Sites\Integration\SiteProjectCommand as SitesCommand;
use App\Domain\SiteIntake\SiteEngineCreateProjectCandidateValidator as PortalValidator;
use App\Domain\SiteIntake\SiteEngineSchemaDriftGate as PortalSchema;
use App\Domain\SiteIntake\SiteEngineContractVocabulary as Vocabulary;
use App\Domain\ServicePortfolio\PortfolioProduct;

$checks=0;
function f151Check(bool $ok,string $why):void {
    global $checks;
    if (!$ok)throw new RuntimeException('F1-151: '.$why);
    ++$checks;
}
function f151Invalid(callable $callback):bool {
    try {$callback();}catch(InvalidArgumentException){return true;}
    return false;
}
$sitesContract=__DIR__.'/../contracts/portal-sites/v1/create-project.schema.json';
$portalSnapshot=__DIR__.'/../portal-current/apps/portal/labs/sites-intake-f55/create-project.schema.snapshot.json';
f151Check((new PortalSchema())->compare($sitesContract,$portalSnapshot)
 ===['matched'=>true,'reason'=>'pinned_schema_match_only'],
 'real Sites contract bytes remain pinned to Portal schema snapshot');

$actual=json_decode((string)file_get_contents($sitesContract),true,16,JSON_THROW_ON_ERROR);
f151Check(($actual['additionalProperties']??null)===false
 && ($actual['required']??null)===Vocabulary::createProjectV1Keys(),
 'both repos agree exact required command vocabulary');
f151Check(Vocabulary::SITES_CREATE_PROJECT_V1==='1',
 'actual Portal version matches Sites v1 candidate');
foreach (['onepage','landing_page'] as $product) {
    $candidate=[
        'schemaVersion'=>'1',
        'operationId'=>'demo-operation-f151',
        'tenantRef'=>'demo-tenant-f151',
        'orderRef'=>'demo-order-f151',
        'contractRef'=>'demo-contract-f151',
        'contractRevision'=>7,
        'productCode'=>$product,
        'idempotencyKey'=>'demo-idempotency-f151',
    ];
    $raw=json_encode($candidate,JSON_THROW_ON_ERROR);
    $portal=PortalValidator::inspect($raw);
    $sites=SitesCommand::fromArray($candidate);
    f151Check($portal===['valid'=>true,'reason'=>'candidate_shape_valid_only'],
        'Portal accepts canonical synthetic '.$product);
    f151Check($sites->productCode===$product
        && $sites->contractRevision===7
        && preg_match('/\A[a-f0-9]{64}\z/D',$sites->semanticFingerprint())===1,
        'Sites parses matching command and computes stable semantic digest');
    $productEnum=Vocabulary::productFromCreateProjectCode($product);
    f151Check($productEnum instanceof PortfolioProduct,
        'Portal maps '.$product.' only to an official product code');

    $extra=$candidate+['tenantOverride'=>'attacker'];
    f151Check(PortalValidator::inspect(json_encode($extra,JSON_THROW_ON_ERROR))['reason']
        ==='unexpected_fields'
        && f151Invalid(static fn()=>SitesCommand::fromArray($extra)),
        'both runtimes reject extra tenant_override field');
    $changed=array_replace($candidate,['productCode'=>'unknown_product']);
    f151Check(PortalValidator::inspect(json_encode($changed,JSON_THROW_ON_ERROR))['reason']
        ==='invalid_product_code'
        && f151Invalid(static fn()=>SitesCommand::fromArray($changed)),
        'both runtimes reject unrecognized product');

    $reordered=array_reverse($candidate,true);
    $reorderedRaw=json_encode($reordered,JSON_THROW_ON_ERROR);
    f151Check(PortalValidator::inspect($reorderedRaw)['reason']==='noncanonical_json',
        'Portal enforces wire key order and canonical JSON bytes');
    // Sites object parses a *typed array*, not raw transport; caller must
    // apply Portal wire canonicalization before claiming end-to-end parity.
    f151Check(SitesCommand::fromArray($reordered)->productCode===$product,
        'Sites array validator alone is not a canonical transport authenticator');

    $duplicated=substr($raw,0,-1).',"productCode":"'.$product.'"}';
    f151Check(PortalValidator::inspect($duplicated)['reason']==='noncanonical_json',
        'Portal rejects duplicate-key wire JSON even when values match');

    $badRevision=array_replace($candidate,['contractRevision'=>0]);
    f151Check(PortalValidator::inspect(json_encode($badRevision,JSON_THROW_ON_ERROR))['reason']
        ==='invalid_contract_revision'
        && f151Invalid(static fn()=>SitesCommand::fromArray($badRevision)),
        'both deny nonpositive contract revision');
}
f151Check(!PortalSchema::authorizesIntegration()
 && !PortalSchema::verifiesRemoteOrigin()
 && !PortalValidator::mayTransmit()
 && !PortalValidator::isAuthenticated()
 && !PortalValidator::isCustomerApprovalVerified()
 && !PortalValidator::mayPublish(),
 'schema parity never authenticates Portal or permits dispatch/publication');
echo "$checks F1-151 real Portal F133/Sites CreateProject parity checks passed. ".
    "No customer acceptance, signed command, endpoint, deploy or lead.\n";
