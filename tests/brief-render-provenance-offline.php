<?php

declare(strict_types=1);

// Reads only the committed synthetic examples. The service is exercised entirely
// in memory, without application boot, credentials, files written, DB or network.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__).'/src/Production/'.substr($class, strlen($prefix)).'.php';
    }
});

use Imagine\Sites\Production\BriefRenderProvenance;
use Imagine\Sites\Production\LocalSiteRendering;
use Imagine\Sites\Production\ReleaseArtifact;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    ++$checks;
};
$encode = static fn (mixed $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
$examples = dirname(__DIR__).'/contracts/portal-sites/candidates/brief-production-v1/examples/';
$base = [
    'plan' => file_get_contents($examples.'brief-render-plan.synthetic.json'),
    'snapshot' => file_get_contents($examples.'brief-snapshot.synthetic.json'),
    'source' => file_get_contents($examples.'onepage-brief.synthetic.json'),
    'editorial' => file_get_contents($examples.'render-input.synthetic.json'),
];
$baseSource = json_decode($base['source'], true, 8, JSON_THROW_ON_ERROR);
$baseSnapshot = json_decode($base['snapshot'], true, 8, JSON_THROW_ON_ERROR);
$basePlan = json_decode($base['plan'], true, 8, JSON_THROW_ON_ERROR);
$baseEditorial = json_decode($base['editorial'], true, 8, JSON_THROW_ON_ERROR);
$expectedHtml = file_get_contents($examples.'rendered.synthetic.html');

$accept = static function (array $input, string $label) use ($ok, $encode): array {
    $unchanged = $input;
    ob_start();
    try { $result = BriefRenderProvenance::render(...array_values($input)); }
    finally { $stdout = ob_get_clean(); }
    $reference = LocalSiteRendering::render($input['editorial']);
    $expectedProvenance = [
        'schemaVersion' => 'sites.local-render-provenance.candidate.v0.1',
        'status' => 'rendered',
        'planSha256' => hash('sha256', $input['plan']),
        'artifact' => ['sha256' => $reference->digest, 'sizeBytes' => $reference->sizeBytes],
        'authorityVerified' => false, 'canPublish' => false, 'canAcceptRealOrders' => false,
    ];
    $ok($stdout === '' && $input === $unchanged && array_keys($result) === ['artifact', 'provenance'] &&
        $result['artifact'] instanceof ReleaseArtifact && $result['artifact']->html === $reference->html &&
        $result['artifact']->digest === $reference->digest && $result['artifact']->sizeBytes === $reference->sizeBytes &&
        $result['provenance'] === $encode($expectedProvenance), $label);
    return $result;
};
$deny = static function (array $input, string $label) use ($ok): void {
    $unchanged = $input; $denied = false;
    ob_start();
    try { BriefRenderProvenance::render(...array_values($input)); }
    catch (Throwable $error) {
        $denied = get_class($error) === RuntimeException::class && $error->getMessage() === 'LOCAL_RENDER_DENIED' &&
            $error->getPrevious() === null;
    } finally { $stdout = ob_get_clean(); }
    $ok($denied && $stdout === '' && $input === $unchanged, $label.' is bounded and leaves input bytes untouched');
};

// Build independently consistent byte links so semantic negatives cannot pass
// merely because an earlier unrelated digest was left stale by the fixture.
$chain = static function (string $source, string $editorial, ?array $snapshot = null, ?array $plan = null) use (
    $baseSnapshot, $basePlan, $encode
): array {
    $snapshot ??= $baseSnapshot; $plan ??= $basePlan;
    $snapshot['payload']['sha256'] = hash('sha256', $source);
    $snapshot['payload']['sizeBytes'] = strlen($source);
    $snapshotJson = $encode($snapshot);
    $plan['snapshot']['manifestSha256'] = hash('sha256', $snapshotJson);
    $plan['renderInput']['sha256'] = hash('sha256', $editorial);
    $plan['renderInput']['sizeBytes'] = strlen($editorial);
    return ['plan' => $encode($plan), 'snapshot' => $snapshotJson, 'source' => $source, 'editorial' => $editorial];
};
$replaceSnapshot = static function (array $input, string $raw) use ($encode): array {
    $input['snapshot'] = $raw;
    $plan = json_decode($input['plan'], true, 8, JSON_THROW_ON_ERROR);
    $plan['snapshot']['manifestSha256'] = hash('sha256', $raw);
    $input['plan'] = $encode($plan);
    return $input;
};
$sourceCase = static fn (array $changes): array => $chain($encode(array_replace($baseSource, $changes)), $base['editorial']);

$first = $accept($base, 'published candidate example follows the existing renderer and exact seven-field sidecar');
$ok($first['artifact']->html === $expectedHtml && $first['artifact']->sizeBytes === 5167 &&
    $first['artifact']->digest === '12549ec46a09e83ea76353a43a48723daa99aa30c6bbdd461923f1a800543d15' &&
    $basePlan['operationId'] !== $baseSnapshot['operationId'] &&
    $basePlan['idempotencyKey'] !== $baseSnapshot['idempotencyKey'] &&
    $baseSnapshot['sourceVersion'] !== $baseSnapshot['briefRevision'],
    'independent frozen artifact and distinct operation/source revision identities are retained');
$second = BriefRenderProvenance::render(...array_values($base));
$ok($second['artifact']->html === $first['artifact']->html && $second['provenance'] === $first['provenance'],
    'same local inputs reproduce exact bytes without claiming durable idempotency');

$snapshot = $baseSnapshot; $plan = $basePlan;
$snapshot['contractRevision'] = 2147483647;
$snapshot['sourceVersion'] = 9007199254740991;
$snapshot['expectedBriefRevision'] = 2147483646;
$snapshot['briefRevision'] = 2147483647;
$plan['snapshot']['briefRevision'] = 2147483647;
$accept($chain($base['source'], $base['editorial'], $snapshot, $plan),
    'maximum independent source/version integers and final declared CAS successor are accepted');

$accept($sourceCase(['company_name' => str_repeat('😀', 80), 'company_intro' => str_repeat('😀', 5000),
    'services' => str_repeat('漢', 10000), 'notes' => str_repeat('é', 2500)]),
    'UTF-16 limits accept exact astral, BMP and decomposed bounds beyond the editorial byte budget');
$deny($sourceCase(['company_name' => str_repeat('😀', 81)]), 'astral source text exceeds UTF-16 bound despite fitting codepoint count');
$deny($sourceCase(['company_name' => str_repeat('😀', 80).' ']), 'length is measured before trimming the logical copy');
$deny($sourceCase(['company_intro' => str_repeat('漢', 10001)]), 'one unit above a long source field bound');
$deny($sourceCase(['notes' => str_repeat('é', 2500).'x']), 'decomposed text is not normalized to evade the source bound');
$accept($sourceCase(['company_name' => "\u{FEFF}\u{2000}Nome sintético\u{2029}",
    'company_intro' => "Primeira linha\r\nSegunda linha é", 'notes' => 'Text 0123 -0 1e0 1.0 stays text']),
    'logical ECMAScript trimming does not rewrite multiline, NFD or number-like source text');
$deny($sourceCase(['company_name' => "\u{FEFF}\u{2028}\u{00A0}\t"]), 'ECMAScript whitespace-only required field');
$accept($sourceCase(['email' => '', 'phone' => "\u{0085}\u{180E}\u{200B}"]),
    'NEL, Mongolian vowel separator and zero-width space are not silently treated as ECMAScript trim characters');
$accept($sourceCase(['company_intro' => "\u{FEFF}", 'ai_support' => true, 'domain' => '',
    'email' => '', 'phone' => 'Contato fictício informado separadamente']),
    'declared AI support can complete an empty introduction without authorizing AI or requiring a domain');

foreach ([
    'company_name' => ['company_name' => ' '],
    'services' => ['services' => "\r\n"],
    'introduction without AI support' => ['company_intro' => '', 'ai_support' => false],
    'all contact choices empty' => ['email' => '', 'phone' => ' ', 'whatsapp' => "\u{FEFF}"],
    'logo availability not supported' => ['logo_unavailable' => false],
    'manual availability not supported' => ['manual_unavailable' => false],
    'truthy string instead of boolean' => ['ai_support' => 'false'],
    'numeric text instead of string' => ['services' => 100],
] as $label => $change) $deny($sourceCase($change), $label);

foreach ([
    'https://example.invalid/caminho/ação?x=%20#fim',
    'HTTP://127.0.0.1:65535/example',
    'https://[2001:db8::1]:443/example',
    "\u{FEFF}https://EXAMPLE.invalid/\u{00A0}",
] as $index => $url) $accept($sourceCase(['current_site' => $url]), 'supported inert HTTP reference '.$index);
foreach ([
    'javascript:alert(1)', 'httpſ://example.invalid/', '//example.invalid/path', 'https://user:secret@example.invalid/',
    'https://@example.invalid/', 'https://example.invalid:65536/', 'https://example.invalid\\other/',
    "https://exam\tple.invalid/", 'https://exemplo.é/', 'http://127.1/',
    'https://xn--example.invalid/', 'https://example.invalid./', 'https://-bad.example.invalid/', 'https://example.0x/',
] as $index => $url) $deny($sourceCase(['contact_site' => $url]), 'unsupported or unsafe URL subset '.$index);
$accept($sourceCase(['email' => "\u{FEFF}ficticio@example.invalid\u{2029}", 'domain' => 'EXAMPLE.INVALID']),
    'legacy email and ASCII-domain syntax use the logical trimmed copy');
foreach ([
    ['email' => 'missing-at.example.invalid'], ['email' => "a\u{FEFF}b@example.invalid"],
    ['domain' => 'https://example.invalid/'], ['domain' => 'bad_name.invalid'], ['domain' => 'exemplo.é'],
] as $index => $change) $deny($sourceCase($change), 'invalid contact syntax '.$index);

// Valid documents can still disagree with the document they identify.
foreach ([
    ['tenantRef' => 'another-synthetic-organization'],
    ['siteRef' => '33333333-3333-4333-8333-333333333333'],
    ['snapshot' => array_replace($basePlan['snapshot'], ['snapshotRef' => '44444444-4444-4444-8444-444444444444'])],
    ['snapshot' => array_replace($basePlan['snapshot'], ['briefRevision' => 2])],
] as $index => $change) {
    $deny($chain($base['source'], $base['editorial'], null, array_replace($basePlan, $change)),
        'individually valid plan selectors disagree with exact snapshot '.$index);
}
$snapshot = array_replace($baseSnapshot, ['briefRevision' => 2]);
$plan = $basePlan; $plan['snapshot']['briefRevision'] = 2;
$deny($chain($base['source'], $base['editorial'], $snapshot, $plan), 'matching documents still have an inconsistent declared CAS');
foreach ([
    'sourceVersion' => 9007199254740992, 'contractRevision' => 0,
    'expectedBriefRevision' => 2147483647, 'sourceState' => 'submitted',
    'sourceProfile' => 'sites.unknown-source.v1', 'productCode' => 'landing_page',
    'operationId' => "op-synthetic\n", 'idempotencyKey' => 1,
    'materials' => (object) [],
] as $field => $value) {
    $deny($chain($base['source'], $base['editorial'], array_replace($baseSnapshot, [$field => $value])),
        'unsupported snapshot '.$field);
}
$snapshot = $baseSnapshot;
$snapshot['materials'] = [['kind' => 'manual', 'payload' => [
    'payloadRef' => 'material-synthetic-001', 'mediaType' => 'application/pdf',
    'sizeBytes' => 100, 'sha256' => str_repeat('a', 64),
]]];
$deny($chain($base['source'], $base['editorial'], $snapshot), 'well-shaped material inventory is explicitly unsupported');

$editorial = array_replace($baseEditorial, ['productCode' => 'landing_page']);
$editorialJson = $encode($editorial); $differentProduct = LocalSiteRendering::render($editorialJson);
$plan = $basePlan; $plan['expectedArtifact'] = ['sha256' => $differentProduct->digest, 'sizeBytes' => $differentProduct->sizeBytes];
$deny($chain($base['source'], $editorialJson, null, $plan), 'valid Landing render and matching expected bytes cannot bypass Onepage source profile');

foreach (['source', 'snapshot', 'editorial'] as $field) {
    $input = $base; $input[$field] .= "\n";
    $deny($input, 'extra LF breaks the original '.$field.' byte link');
}
$input = $base; $input['plan'] .= "\n";
$changedPlan = $accept($input, 'plan serialization is hashed exactly without semantic canonicalization');
$ok($changedPlan['artifact']->html === $first['artifact']->html && $changedPlan['provenance'] !== $first['provenance'],
    'changing only plan bytes changes provenance while preserving the exact artifact');
$accept($chain($base['source']."\n", $base['editorial']."\n"), 'explicitly relinked source/editorial LF bytes remain valid');

foreach (['sha256' => str_repeat('b', 64), 'sizeBytes' => strlen($base['source']) + 1,
    'mediaType' => 'text/plain'] as $key => $value) {
    $snapshot = $baseSnapshot; $snapshot['payload'][$key] = $value;
    $deny($replaceSnapshot($base, $encode($snapshot)), 'source payload descriptor mismatch '.$key);
}
foreach (['sha256' => str_repeat('b', 64), 'sizeBytes' => strlen($base['editorial']) + 1,
    'mediaType' => 'text/plain'] as $key => $value) {
    $input = $base; $plan = $basePlan; $plan['renderInput'][$key] = $value; $input['plan'] = $encode($plan);
    $deny($input, 'editorial payload descriptor mismatch '.$key);
}
foreach ([
    ['snapshot', 'manifestSha256', str_repeat('b', 64)],
    ['expectedArtifact', 'sha256', str_repeat('b', 64)],
    ['expectedArtifact', 'sizeBytes', 5168],
] as [$object, $key, $value]) {
    $input = $base; $plan = $basePlan; $plan[$object][$key] = $value; $input['plan'] = $encode($plan);
    $deny($input, 'validly shaped but incorrect '.$object.'.'.$key);
}

foreach (['plan', 'snapshot', 'source', 'editorial'] as $field) {
    $input = $base; $input[$field] = '[]';
    if ($field === 'source') $input = $chain('[]', $base['editorial']);
    if ($field === 'editorial') $input = $chain($base['source'], '[]');
    if ($field === 'snapshot') $input = $replaceSnapshot($base, '[]');
    $deny($input, 'array root is not a document object '.$field);
    $decoded = json_decode($base[$field], true, 8, JSON_THROW_ON_ERROR);
    $decoded['authorityVerified'] = true;
    $input = $base; $input[$field] = $encode($decoded);
    if ($field === 'source') $input = $chain($input[$field], $base['editorial']);
    if ($field === 'editorial') $input = $chain($base['source'], $input[$field]);
    if ($field === 'snapshot') $input = $replaceSnapshot($base, $input[$field]);
    $deny($input, 'authority claim is an unknown field '.$field);
}
$duplicateSource = '{"company_\u006eame":"substituted",'.substr($base['source'], 1);
$deny($chain($duplicateSource, $base['editorial']), 'decoded duplicate key in rich source with a correct raw digest');
$duplicateEditorial = '{"product\u0043ode":"onepage",'.substr($base['editorial'], 1);
$deny($chain($base['source'], $duplicateEditorial), 'existing renderer rejects escaped editorial duplicate with a correct raw digest');
$duplicateSnapshot = str_replace('"sizeBytes": 861', '"sizeBytes": 861, "size\u0042ytes": 861', $base['snapshot']);
$deny($replaceSnapshot($base, $duplicateSnapshot), 'nested duplicate descriptor key with a correct snapshot manifest digest');
$input = $base;
$input['plan'] = str_replace('"sizeBytes": 5167', '"sizeBytes": 5167, "size\u0042ytes": 5167', $base['plan']);
$deny($input, 'nested duplicate expected artifact key');

foreach (['12.0', '12e0', '"12"', '-0', '9223372036854775808'] as $numeric) {
    $raw = str_replace('"sourceVersion": 12', '"sourceVersion": '.$numeric, $base['snapshot']);
    $deny($replaceSnapshot($base, $raw), 'noncanonical or unsafe source-version token '.$numeric);
}
foreach (['5167.0', '5.167e3', '"5167"'] as $numeric) {
    $input = $base;
    $input['plan'] = str_replace('"sizeBytes": 5167', '"sizeBytes": '.$numeric, $base['plan']);
    $deny($input, 'noninteger expected artifact size '.$numeric);
}
foreach (['company_name', 'services', 'notes'] as $field) {
    $deny($sourceCase([$field => "prefix\0suffix"]), 'escaped NUL in rich '.$field);
}
$deny($chain($base['source'], $encode(array_replace($baseEditorial, ['headline' => "prefix\0suffix"]))),
    'escaped NUL remains refused by the existing editorial parser');
$deny($chain(str_replace('Oficina', "Ofic\xFFina", $base['source']), $base['editorial']), 'malformed source UTF-8');
$deny($chain(str_replace('Oficina', '\ud800', $base['source']), $base['editorial']), 'unpaired source surrogate');
$input = $base; $input['plan'] .= "\0";
$deny($input, 'raw NUL after plan JSON');
$input = $base; $input['plan'] = substr($input['plan'], 0, -2);
$deny($input, 'truncated plan JSON');

// Check exact transport ceilings using valid JSON padding and freshly linked
// hashes, not invalid giant field content or mismatched earlier descriptors.
$bounded = $chain(str_pad($base['source'], 150000, ' '), str_pad($base['editorial'], 32768, ' '));
$bounded = $replaceSnapshot($bounded, str_pad($bounded['snapshot'], 32768, ' '));
$bounded['plan'] = str_pad($bounded['plan'], 32768, ' ');
$accept($bounded, 'all four exact byte ceilings preserve parser and source units');
$deny($chain(str_pad($base['source'], 150001, ' '), $base['editorial']), 'source one byte above private reader ceiling');
$deny($chain($base['source'], str_pad($base['editorial'], 32769, ' ')), 'editorial one byte above existing parser ceiling');
$deny($replaceSnapshot($base, str_pad($base['snapshot'], 32769, ' ')), 'snapshot one byte above manifest ceiling');
$input = $base; $input['plan'] = str_pad($input['plan'], 32769, ' ');
$deny($input, 'plan one byte above manifest ceiling');

echo "BRIEF RENDER PROVENANCE OFFLINE PASS: $checks checks; exact candidate byte links, separate source/editorial content, bounded local-only result.\n";
