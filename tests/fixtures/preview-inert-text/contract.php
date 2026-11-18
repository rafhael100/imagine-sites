<?php
declare(strict_types=1);

use App\Preview\PreviewBriefInput;
use Imagine\Sites\Production\{PrivatePreviewComparison,PrivatePreviewHistory};

// Included only after the parent's disposable cluster, owner and RLS checks.
// These reserved domains and markup-like strings are fictional plain text.
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_LOCAL_ROLE_CONTRACT') !== '1' ||
    !isset($admin,$connections,$workspaces,$companies,$ok,$count,$historyDigest)) {
    throw new RuntimeException('Inert text requires the verified role fixture');
}
$inertChecksBefore = $count;
$inertProjects = ['a'=>'92000000-0000-4000-8000-000000000001','b'=>'92000000-0000-4000-8000-000000000002'];
$inertOriginal = [
    'brand' => 'Marca https://marca.example.invalid/?a=1&b=2 "A" <Demo>',
    'headline' => 'onclick="abrir()" é texto: http://guia.example.invalid/ <guia>',
    'description' => 'onboarding=aberto. src="https://cdn.example.invalid/a.png" e onfocus="texto". '.
        '<img src=x onerror="alert(1)"> Também javascript:exemplo & café.',
    'call_to_action' => 'Leia https://acao.example.invalid/ onclick="texto"',
    'benefits' => "URL literal https://beneficio.example.invalid/?a=1&b=2\n".
        'Texto <script>exemplo</script> e data:text/html,exemplo',
];
$inertRevised = $inertOriginal;
$inertRevised['description'] = 'onboarding=concluído. style="url(https://estilo.example.invalid/)" é texto. '.
    'onclick="outro()" e &quot; são literais, assim como <iframe src="https://quadro.example.invalid/"></iframe>.';
$inertRevised['benefits'] = "javascript:apenas-texto e @import url(https://css.example.invalid/)\n".
    'Uma referência http://revisao.example.invalid/ com onload="somente texto"';
$inertCanonical = static function (array $value): array { ksort($value); return $value; };
$inertState = static fn (): array => [$historyDigest(),$admin->query(
    "SELECT md5(string_agg(row_to_json(p)::text,'' ORDER BY id)) FROM sites_preview.projects p"
)->fetchColumn()];
$inertRead = $admin->prepare('SELECT revision,brief::text,html,sha256,created_at::text
    FROM sites_preview.versions WHERE project_id=?::uuid AND company_id=?::uuid AND revision=?');
$inertRows = []; $inertBriefs = [];

foreach (['a'=>'onepage','b'=>'landing_page'] as $tenant => $product) {
    foreach ([1=>$inertOriginal,2=>$inertRevised] as $revision => $input) {
        $brief = PreviewBriefInput::parse(['product'=>$product]+$input);
        $inertBriefs[$tenant][$revision] = get_object_vars($brief);
        $saved = $revision === 1
            ? $workspaces[$tenant]['author']->createDemonstration($inertProjects[$tenant],$companies[$tenant],$brief)
            : $workspaces[$tenant]['author']->revise($inertProjects[$tenant],$companies[$tenant],1,$brief);
        $inertRead->execute([$inertProjects[$tenant],$companies[$tenant],$revision]);
        $row = $inertRead->fetch(PDO::FETCH_ASSOC); $inertRows[$tenant][$revision] = $row;
        $decoded = json_decode($row['brief'],true,12,JSON_THROW_ON_ERROR);
        $ok($inertCanonical($decoded) === $inertCanonical(get_object_vars($brief)) &&
            $row['sha256'] === $saved['sha256'] && hash('sha256',$row['html']) === $row['sha256'] &&
            $saved['revision'] === $revision && $saved['publishable'] === false && $saved['client_accepted'] === false,
            'inert URL and attribute-like text survives JSONB and exact artifact digest: '.$product.' revision '.$revision);

        // Inspect the parsed document without serializing or rewriting it. A
        // literal URL must not become navigation; quotes and escaped tags must
        // remain inside their original text/metadata values, not attributes.
        $document = new DOMDocument('1.0','UTF-8');
        $loaded = $document->loadHTML($row['html'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $description = $xpath->query('//meta[@name="description"]')->item(0);
        $text = static fn (string $query): ?string => $xpath->query($query)->item(0)?->textContent;
        $benefits = [];
        foreach ($xpath->query('//section[@id="beneficios"]//li') as $node) $benefits[] = $node->textContent;
        $links = [];
        foreach ($xpath->query('//a') as $node) $links[] = $node->getAttribute('href');
        $activeAttributes = false;
        foreach ($xpath->query('//*') as $node) foreach ($node->attributes as $attribute) {
            if (preg_match('/\Aon[a-z]+\z/iD',$attribute->nodeName) === 1 ||
                in_array($attribute->nodeName,['src','srcset','ping','action','formaction'],true)) $activeAttributes = true;
        }
        $ok($loaded && $description instanceof DOMElement && $description->attributes->length === 2 &&
            $description->getAttribute('content') === $brief->description &&
            $text('//title') === $brief->headline.' | '.$brief->brand &&
            $text('//span[@class="brand"]') === $brief->brand &&
            $text('//*[@id="hero-title"]') === $brief->headline &&
            $text('//p[@class="lead"]') === $brief->description &&
            $text('//a[@class="btn"]') === $brief->callToAction && $benefits === $brief->benefits &&
            $links === ($product === 'onepage' ? ['#beneficios','#sobre','#contato'] : ['#contato']) &&
            !$activeAttributes && $xpath->query('//script|//iframe|//img|//form|//object|//embed|//link')->length === 0,
            'inert metadata and body preserve literal text without active attributes or external targets: '.$product.' revision '.$revision);
    }

    $inertBefore = $inertState();
    $comparison = (new PrivatePreviewComparison($connections[$tenant]['reader']))->compare(
        $companies[$tenant],$inertProjects[$tenant],1,2);
    $ok($comparison['from']['revision'] === 1 && $comparison['to']['revision'] === 2 &&
        $comparison['from']['brief'] === $inertBriefs[$tenant][1] &&
        $comparison['to']['brief'] === $inertBriefs[$tenant][2] &&
        $comparison['from']['sha256'] === $inertRows[$tenant][1]['sha256'] &&
        $comparison['to']['sha256'] === $inertRows[$tenant][2]['sha256'] &&
        $comparison['from']['sha256'] !== $comparison['to']['sha256'] &&
        $comparison['changed_fields'] === ['description','benefits'],
        'real persisted comparison accepts inert URL/handler-like copy with exact revisions and changed fields: '.$product);
    $history = (new PrivatePreviewHistory($connections[$tenant]['reader']))->page($companies[$tenant],$inertProjects[$tenant]);
    $unchanged = true;
    foreach ([1,2] as $revision) {
        $inertRead->execute([$inertProjects[$tenant],$companies[$tenant],$revision]);
        $view = $workspaces[$tenant]['reader']->view($inertProjects[$tenant],$companies[$tenant],$revision);
        $unchanged = $unchanged && $inertRead->fetch(PDO::FETCH_ASSOC) === $inertRows[$tenant][$revision] &&
            $view['html'] === $inertRows[$tenant][$revision]['html'] &&
            $view['sha256'] === $inertRows[$tenant][$revision]['sha256'];
    }
    $ok($unchanged && $history['brief'] === $inertBriefs[$tenant][2] && $history['current_revision'] === 2 &&
        $history['current_sha256'] === $inertRows[$tenant][2]['sha256'] &&
        array_column($history['versions'],'revision') === [2,1] && $history['reviews'] === [] &&
        $history['current_reviews'] === [] && $inertState() === $inertBefore,
        'comparison and historical reads preserve all bytes, timestamps, digests, rows and review state: '.$product);
}

// Both targets were positively read above. Test a foreign project under the
// caller's own company and a forged company selector using the same SQL login.
$inertBefore = $inertState();
foreach (['a'=>'b','b'=>'a'] as $actor => $other) {
    foreach (['own'=>$companies[$actor],'foreign'=>$companies[$other]] as $selector => $company) {
        $refused = false;
        try {
            (new PrivatePreviewComparison($connections[$actor]['reader']))->compare($company,$inertProjects[$other],1,2);
        } catch (RuntimeException $error) {
            $refused = get_class($error) === ($selector === 'own' ? OutOfBoundsException::class : RuntimeException::class) &&
                $error->getMessage() === 'Private preview comparison unavailable';
        }
        $ok($refused && $inertState() === $inertBefore,
            'valid inert text cannot bypass comparison company isolation: '.$actor.' '.$selector.' selector');
    }
}
echo 'INERT TEXT SQL PASS: '.($count-$inertChecksBefore).
    " assertions; both products, literal URLs and attribute-like copy, metadata/body escaping, immutable comparison/history and tenant isolation\n";
