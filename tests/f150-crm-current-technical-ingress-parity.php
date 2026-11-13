<?php

declare(strict_types=1);

/**
 * Sites F1-150: exercise current CRM F3-48 *real PHP source* (pinned),
 * not reimplemented CRM logic. Pure synthetic metadata, zero I/O.
 */
spl_autoload_register(static function (string $class): void {
    foreach ([
        'Imagine\\Sites\\Integration\\' => __DIR__.'/../src/Integration/',
        'App\\Core\\Leads\\' => __DIR__.'/../crm-current/app/Core/Leads/',
    ] as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) continue;
        $basename = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $basename)) {
            throw new RuntimeException('Unsafe class path in fixture');
        }
        require_once $dir.$basename.'.php';
        return;
    }
});

use Imagine\Sites\Integration\SyntheticCrmLeadEnvelopeV1Draft as SitesDraft;
use App\Core\Leads\PortalTechnicalEnvelopeDecoder as Decoder;
use App\Core\Leads\PortalIngressPreparationBoundary as Boundary;

$checks = 0;
function f150Check(bool $ok, string $what): void {
    global $checks;
    if (!$ok) throw new RuntimeException('F1-150: '.$what);
    $checks++;
}
function f150DenyAll(object $report, string $why): void {
    f150Check(
        !$report->canAuthenticateService()
        && !$report->canAcceptLead()
        && !$report->canPersistLead()
        && !$report->canAcknowledgeVisitor()
        && !$report->canQueueDelivery()
        && !$report->canRetryUnknownOutcome()
        && !$report->canOpenPublicRoute(),
        $why.' must grant zero operational permissions'
    );
}

$draft = new SitesDraft(
    '11111111-1111-4111-8111-111111111111',
    '22222222-2222-4222-8222-222222222222',
    '33333333-3333-4333-8333-333333333333',
    '44444444-4444-4444-8444-444444444444',
    '55555555-5555-4555-8555-555555555555',
    '66666666-6666-4666-8666-666666666666',
);
$fields = $draft->metadata();
$raw = json_encode($fields, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$decoded = (new Decoder())->decode($raw);
f150Check($decoded === $fields,
    'current CRM strict decoder accepts Sites original eight fields');
f150Check(!$draft->canAuthorizeDelivery(),
    'Sites metadata-only draft never allows delivery');

$binding = [
    'organization_reference' => $draft->organizationReference,
    'binding_reference' => $draft->bindingReference,
    'site_reference' => $draft->siteReference,
    'form_reference' => $draft->formReference,
    'destination_reference' => $draft->destinationReference,
];
// IMPORTANT: in this test binding values are prearranged synthetic fixtures.
// Copying metadata is NOT independent server-side Portal authority.
$now = 2000000000;
$key = str_repeat('f', 48); // Fictional CI-only string, NEVER a production secret
$nonce = '77777777-7777-4777-8777-777777777777';
$issued = $now-10;
$expires = $now+120;
$makeAuth = static function(string $operation) use (
    $fields, $key, $nonce, $issued, $expires
): array {
    $domain = match($operation) {
        'deliver'=>'imagine.portal.synthetic-service-request.v1',
        'lookup'=>'imagine.portal.synthetic-crm-lookup.v1',
    };
    $message = [$domain];
    foreach ([
        'organization_reference','binding_reference','submission_reference',
        'site_reference','form_reference','destination_reference'
    ] as $name) $message[] = strtolower($fields[$name]);
    $message[] = (string)$issued;
    $message[] = (string)$expires;
    $message[] = $nonce;
    return [
        'issued_at'=>$issued, 'expires_at'=>$expires,
        'nonce'=>$nonce, 'mac'=>hash_hmac('sha256', implode("\n",$message), $key),
    ];
};
$boundary = new Boundary();
foreach (['deliver','lookup'] as $operation) {
    $auth = $makeAuth($operation);
    $assessment = $boundary->inspect(
        $raw, $binding, $auth, $now, $key, $operation
    );
    f150Check($assessment->reasonCode === 'LAB_TECHNICAL_METADATA_MATCH_UNTRUSTED'
        && $assessment->isMetadataSyntaxAndMacConsistent(),
        'current CRM composition recognizes synthetic '.$operation.' MAC only');
    f150DenyAll($assessment, 'matching '.$operation.' HMAC and metadata');
}

$auth = $makeAuth('deliver');
$negative = [
    ['TECHNICAL_JSON_REJECTED',
        substr($raw,0,-1).',"email":"fake@example.invalid"}',
        $binding,$auth,'deliver'],
    ['TECHNICAL_JSON_REJECTED',
        str_replace('"site_reference":"'.$fields['site_reference'].'"',
            '"site_reference":{"nested":true}', $raw),
        $binding,$auth,'deliver'],
    ['DUPLICATE_JSON_KEY_REJECTED',
        str_replace('"destination_reference":','"organization_reference":',$raw),
        $binding,$auth,'deliver'],
    ['TECHNICAL_JSON_REJECTED',
        substr($raw,0,-1),$binding,$auth,'deliver'],
    ['PINNED_CONTEXT_MISMATCH',$raw,
        array_replace($binding, [
            'destination_reference'=>'99999999-9999-4999-8999-999999999999',
        ]),$auth,'deliver'],
    ['AUTH_MAC_MISMATCH',$raw,$binding,
        array_replace($auth,['mac'=>str_repeat('0',64)]),'deliver'],
    ['AUTH_WINDOW_INVALID',$raw,$binding,
        array_replace($auth,['expires_at'=>$issued]),'deliver'],
    ['OPERATION_UNSUPPORTED',$raw,$binding,$auth,'delete'],
];
foreach ($negative as [$expected,$json,$pinned,$authData,$op]) {
    $assessment=$boundary->inspect($json,$pinned,$authData,$now,$key,$op);
    f150Check($assessment->reasonCode===$expected,
        'CRM F3-48 denies '.$expected.' without silently falling back');
    f150DenyAll($assessment, $expected);
}

$tooLarge = str_repeat('x', Decoder::MAX_BYTES+1);
$invalid=$boundary->inspect($tooLarge,$binding,$auth,$now,$key,'deliver');
f150Check($invalid->reasonCode==='TECHNICAL_JSON_REJECTED',
    'oversized candidate fails closed before authorization');
f150DenyAll($invalid,'oversized untrusted metadata');

echo "$checks F1-150 cross-repo CRM F3-48 PHP compatibility checks passed. ".
    "No customer PII, Portal IAM, transport, nonce store, CRM intake or receipt.\n";
