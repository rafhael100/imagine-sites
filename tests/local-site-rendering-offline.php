<?php

declare(strict_types=1);

// Real in-memory rendering only. No private input/output files, application
// boot, database, provider, Portal payload or commercial authority is simulated.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Imagine\\Sites\\Production\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../src/Production/'.substr($class, strlen($prefix)).'.php';
        if (is_file($path)) require_once $path;
    }
});

use Imagine\Sites\Production\InertPreviewAudit;
use Imagine\Sites\Production\LocalSiteRendering;
use Imagine\Sites\Production\ReleaseArtifact;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;

$checks = 0;
$ok = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Offline local rendering failure: '.$label);
    ++$checks;
};
$deny = static function (string $json, string $label) use ($ok): void {
    $error = null;
    ob_start();
    try { LocalSiteRendering::render($json); } catch (Throwable $caught) { $error = $caught; }
    $output = ob_get_clean();
    $ok($error !== null && $error::class === RuntimeException::class &&
        $error->getMessage() === 'LOCAL_RENDER_DENIED' && $error->getPrevious() === null &&
        $error->getCode() === 0 && $output === '', $label.' has only the bounded denial');
};
$encode = static fn (mixed $value): string => json_encode($value,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
$renderer = new StaticSiteRenderer();
$base = [
    'productCode' => 'onepage',
    'brand' => 'Marca fictícia 😀',
    'headline' => "Cafe\u{0301} e conteúdo de exemplo",
    'description' => "Linha um\r\nLinha dois: https://example.invalid/ e onclick=\"literal\". ".
        '<a href="https://reference.example.invalid/">Texto</a> & {"brand":"literal"}: [fim]\\',
    'callToAction' => 'Conheça & confira',
    'benefits' => ['Primeiro benefício 😀', 'Texto <script>inerte</script> e &lt;strong&gt;literal'],
];
$sameAsDirect = static function (array $input, string $label) use ($renderer, $encode, $ok): ReleaseArtifact {
    $direct = $renderer->render(new SiteBrief(...$input));
    ob_start();
    $actual = LocalSiteRendering::render($encode($input));
    $output = ob_get_clean();
    $ok($actual->html === $direct->html && $actual->digest === $direct->digest &&
        $actual->sizeBytes === $direct->sizeBytes && $output === '',
        $label.' preserves every direct-renderer byte and returns no content on stdout');
    return $actual;
};
$products = [];
foreach (['onepage', 'landing_page'] as $product) {
    $input = array_replace($base, ['productCode' => $product]);
    $artifact = $sameAsDirect($input, $product);
    $again = LocalSiteRendering::render($encode($input));
    $ok($artifact->html === $again->html && $artifact->digest === hash('sha256', $artifact->html) &&
        $artifact->sizeBytes === strlen($artifact->html) && $again->digest === $artifact->digest,
        $product.' renders deterministically with the exact digest and size');
    $ok(InertPreviewAudit::isSafe($artifact->html) && mb_check_encoding($artifact->html, 'UTF-8') &&
        !str_contains($artifact->html, "\0") &&
        str_contains($artifact->html, 'Prévia demonstrativa: formulários e envio de dados permanecem desabilitados.') &&
        str_contains($artifact->html, 'demonstração sem publicação e sem captação de dados.'),
        $product.' remains the existing inert demonstrative artifact');
    $ok(str_contains($artifact->html, "Linha um\r\nLinha dois") &&
        str_contains($artifact->html, "Cafe\u{0301}") &&
        str_contains($artifact->html, '&lt;script&gt;inerte&lt;/script&gt;') &&
        str_contains($artifact->html, '&amp;lt;strong&amp;gt;literal') &&
        !str_contains($artifact->html, '<script>'),
        $product.' preserves CRLF and decomposed text while keeping markup and entities literal');
    $products[$product] = $artifact;
}
$ok($products['onepage']->digest !== $products['landing_page']->digest &&
    str_contains($products['onepage']->html, '<body data-site-product="onepage">') &&
    str_contains($products['landing_page']->html, '<body data-site-product="landing-page">'),
    'the two supported products retain their distinct original layouts');

$json = $encode($base);
$artifact = $products['onepage'];
foreach ([
    'object order' => $encode(array_reverse($base, true)),
    'JSON whitespace and escaped Unicode' => json_encode($base, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    'one escaped key' => str_replace('"brand":', '"\u0062rand":', $json),
    'escaped product key' => str_replace('"productCode":', '"\u0070roductCode":', $json),
    'exact transport byte bound' => str_pad($json, LocalSiteRendering::MAX_INPUT_BYTES, " \t\r\n"),
] as $label => $equivalent) {
    $result = LocalSiteRendering::render($equivalent);
    $ok($result->html === $artifact->html && $result->digest === $artifact->digest &&
        $result->sizeBytes === $artifact->sizeBytes, $label.' does not change decoded text or HTML bytes');
}
$spaced = $base;
$spaced['brand'] = " \tMarca fictícia 😀 \r\n";
$spaced['benefits'] = ["\tBenefício fictício \r\n"];
$sameAsDirect($spaced, 'valid leading and trailing whitespace with one benefit');
$nfc = array_replace($base, ['headline' => 'Café e conteúdo de exemplo']);
$lf = array_replace($base, ['description' => str_replace("\r\n", "\n", $base['description'])]);
$ok(LocalSiteRendering::render($encode($nfc))->digest !== $artifact->digest &&
    LocalSiteRendering::render($encode($lf))->digest !== $artifact->digest,
    'NFC and LF inputs do not replace the distinct original NFD and CRLF bytes');

$maximum = [
    'productCode' => 'onepage',
    'brand' => str_repeat('😀', 120),
    'headline' => str_repeat('😀', 180),
    'description' => str_repeat('😀', 600),
    'callToAction' => str_repeat('😀', 80),
    'benefits' => array_fill(0, 8, str_repeat('😀', 180)),
];
foreach (['onepage', 'landing_page'] as $product) {
    $maximum['productCode'] = $product;
    $maxArtifact = $sameAsDirect($maximum, $product.' at every four-byte code-point limit');
    $escapedMaximum = json_encode($maximum, JSON_THROW_ON_ERROR);
    $ok(strlen($escapedMaximum) <= LocalSiteRendering::MAX_INPUT_BYTES &&
        LocalSiteRendering::render($escapedMaximum)->html === $maxArtifact->html &&
        $maxArtifact->sizeBytes <= 150000,
        $product.' fully escaped maximum input fits the bound and the downstream artifact limit');
}
$combining = array_replace($base, ['headline' => str_repeat("e\u{0301}", 90)]);
$sameAsDirect($combining, '180 code points in 90 decomposed graphemes');
$combining['headline'] .= 'x';
$deny($encode($combining), 'one extra code point after decomposed text');

foreach (array_keys($base) as $field) {
    $missing = $base;
    unset($missing[$field]);
    $deny($encode($missing), 'missing field '.$field);
    $duplicate = '{'.$encode($field).':'.$encode($base[$field]).','.substr($json, 1);
    $deny($duplicate, 'duplicate field '.$field.' even with an equal value');
}
foreach ([
    '{"\u0062rand":"Marca fictícia 😀",'.substr($json, 1),
    '{"brand":"Marca fictícia 😀",'.substr(str_replace('"brand":', '"\u0062rand":', $json), 1),
    '{"\u0070roductCode":"onepage",'.substr($json, 1),
] as $index => $duplicate) {
    $deny($duplicate, 'equivalent escaped duplicate key '.$index);
}
foreach ([
    'schema' => 'SITES_PORTAL_BRIEF_V1', 'siteRef' => 'not-an-authority',
    'expectedRevision' => 1, 'canPublish' => true, 'outputPath' => '/not-an-output',
] as $field => $value) {
    $deny($encode($base + [$field => $value]), 'unexpected metadata or authority field '.$field);
}
foreach (['productCode', 'brand', 'headline', 'description', 'callToAction'] as $field) {
    foreach ([null, true, 1, 1.5, [], new stdClass()] as $index => $value) {
        $deny($encode(array_replace($base, [$field => $value])), 'non-string '.$field.' type '.$index);
    }
}
foreach (['', 'landing-page', 'ONEPAGE', 'onepage ', 'blog'] as $index => $product) {
    $deny($encode(array_replace($base, ['productCode' => $product])), 'unsupported product '.$index);
}
foreach (['brand' => 120, 'headline' => 180, 'description' => 600, 'callToAction' => 80] as $field => $limit) {
    foreach (['', " \t\r\n"] as $index => $blank) {
        $deny($encode(array_replace($base, [$field => $blank])), 'blank '.$field.' variant '.$index);
    }
    $deny($encode(array_replace($base, [$field => str_repeat('😀', $limit + 1)])),
        'one code point above '.$field.' limit');
    foreach (["\0".$base[$field], 'inicio'."\0".'fim', $base[$field]."\0"] as $index => $nul) {
        $deny($encode(array_replace($base, [$field => $nul])), 'NUL in '.$field.' position '.$index);
    }
}
foreach ([null, '', true, 1, new stdClass(), (object) ['0' => 'Benefício'], [1 => 'Benefício']] as $index => $value) {
    $deny($encode(array_replace($base, ['benefits' => $value])), 'benefits is not a JSON list '.$index);
}
foreach ([[], array_fill(0, 9, 'Benefício')] as $index => $value) {
    $deny($encode(array_replace($base, ['benefits' => $value])), 'benefit count outside existing bounds '.$index);
}
foreach ([null, false, 1, 1.5, [], new stdClass(), '', " \t\r\n", str_repeat('😀', 181),
    "\0inicio", "meio\0fim", "fim\0"] as $index => $value) {
    $deny($encode(array_replace($base, ['benefits' => ['Benefício válido', $value]])),
        'invalid benefit value '.$index);
}

foreach ([
    'empty JSON' => '', 'root array' => '[]', 'root null' => 'null',
    'root boolean' => 'true', 'root string' => '"brief"', 'root number' => '1',
    'truncated JSON' => substr($json, 0, -1), 'trailing JSON' => $json.'{}',
    'trailing comma' => substr($json, 0, -1).',}', 'comment prefix' => '/*brief*/'.$json,
    'raw NUL' => $json."\0", 'invalid UTF-8 text' => str_replace($base['brand'], "Marca\xFF", $json),
    'unpaired surrogate' => str_replace($encode($base['brand']), '"\ud800"', $json),
    'nested depth' => '{"benefits":'.str_repeat('[', 10).'"Exemplo"'.str_repeat(']', 10).'}',
    'one byte above input bound' => str_pad($json, LocalSiteRendering::MAX_INPUT_BYTES + 1, ' '),
] as $label => $invalid) {
    $deny($invalid, $label);
}

echo "LOCAL SITE RENDERING OFFLINE PASS: $checks checks; exact inert renderer bytes, strict local input, no artifact writes or external authority.\n";
