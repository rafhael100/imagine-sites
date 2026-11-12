<?php

declare(strict_types=1);

/**
 * F1-243 independent DOM verification of the F1-238 six local rich previews.
 * No network, IAM, customer PII, HTML publishing, or operational permission.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('SITES_F238_DEMO_ARTIFACT')!=='1') {
    throw new RuntimeException('F1-243 test requires explicit CI sandbox flag.');
}
$dir=__DIR__.'/../apps/sites/storage/app/private/f238-demo-previews';
if (!is_dir($dir) || is_link($dir)) {
    throw new RuntimeException('F1-243 expected generated private directory.');
}
$manifest=json_decode(
    (string)file_get_contents($dir.'/manifest.json'),
    true,16,JSON_THROW_ON_ERROR,
);
if (!is_array($manifest) ||
    $manifest['schema']!=='imagine-sites.synthetic-demo-gallery.v1' ||
    $manifest['richSections']!==['highlights','process','faq'] ||
    $manifest['editorialFixture']!=='fixtures/sites/f1-170-process-copy.synthetic.json' ||
    $manifest['publishable']!==false ||
    $manifest['approvedForClient']!==false ||
    $manifest['hasContactCapture']!==false ||
    count($manifest['files'])!==6) {
    throw new RuntimeException('F1-243 rich manifest or go-live flags invalid.');
}
$checks=0;
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $name="$product-$style.html";
        $info=$manifest['files'][$name]??null;
        if (!is_array($info) || $info['product']!==$product ||
            $info['preset']!==$style ||
            $info['sections']!==['benefits','highlights','process','faq','contact'] ||
            $info['navigation']!==($product==='onepage'?'section_anchors':'local_cta') ||
            $info['socialMetadata']!==true) {
            throw new RuntimeException('F1-243 rich page inventory mismatch.');
        }
        if (is_link($dir.'/'.$name)) {
            throw new RuntimeException('F1-243 symlinked sample denied.');
        }
        $html=(string)file_get_contents($dir.'/'.$name);
        if (strlen($html)!==$info['bytes'] ||
            !hash_equals(hash('sha256',$html),$info['sha256'])) {
            throw new RuntimeException('F1-243 rich content SHA-256 mismatch.');
        }
        $previous=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument('1.0','UTF-8');
            if (!$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR)) {
                throw new RuntimeException('F1-243 browser document invalid.');
            }
            $xp=new DOMXPath($dom);
            $count=static fn(string $q):int=>($xp->query($q)?->length)??0;
            foreach([
                '//main[@id="principal"]'=>1,
                '//section[@id="beneficios"]'=>1,
                '//section[@id="solucoes"]'=>1,
                '//section[@id="etapas"]'=>1,
                '//section[@id="perguntas"]'=>1,
                '//section[@id="contato"]'=>1,
                '//section[@id="solucoes"]//li'=>2,
                '//section[@id="etapas"]//ol/li'=>3,
                '//section[@id="perguntas"]//li'=>2,
                '//meta[@property="og:type"]'=>1,
                '//meta[@property="og:title"]'=>1,
                '//meta[@property="og:description"]'=>1,
                '//meta[@name="twitter:card"]'=>1,
                '//meta[@name="twitter:title"]'=>1,
                '//meta[@name="twitter:description"]'=>1,
                '//meta[@name="robots" and @content="noindex, nofollow"]'=>1,
                '//form|//script|//iframe|//input|//button|//object|//embed'=>0,
                '//nav/a[@href="#solucoes"]'=>$product==='onepage'?1:0,
                '//nav/a[@href="#etapas"]'=>$product==='onepage'?1:0,
                '//nav/a[@href="#perguntas"]'=>$product==='onepage'?1:0,
            ] as $xpath=>$expected) {
                $actual=$count($xpath);
                if ($actual!==$expected) {
                    throw new RuntimeException("F1-243 $name XPath discrepancy: $xpath = $actual (wanted $expected)");
                }
                ++$checks;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (preg_match('~(?:https?://|javascript:|data:|<form|<script)~i',$html)) {
            throw new RuntimeException('F1-243 unexpected active/remote content.');
        }
        ++$checks;
    }
}
echo "PASS F1-243: $checks rich HTML structure/content/noindex checks across six real rendered pages.\n";
