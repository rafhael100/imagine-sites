<?php

declare(strict_types=1);

/** F1-75: private bundle requires one canonical, unambiguous JSON manifest. */
spl_autoload_register(static function(string $class):void {
    $prefix='Imagine\\Sites\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    if(!preg_match('/\A(?:Domain|Production)\\\\[A-Za-z][A-Za-z0-9]*\z/D',$relative)){
        throw new RuntimeException('Unsafe F1-75 class path');
    }
    require __DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
});
use Imagine\Sites\Domain\ReleaseManifest;
use Imagine\Sites\Production\SiteBrief;
use Imagine\Sites\Production\StaticSiteRenderer;
use Imagine\Sites\Production\SyntheticReleaseBundle;

$total=0;
function f75Check(bool $passed,string $label):void {
    global $total;
    if(!$passed)throw new RuntimeException('FAIL '.$label);
    ++$total; echo "PASS $label\n";
}

$renderer=new StaticSiteRenderer();
foreach(['onepage','landing_page'] as $product) {
    foreach(['classic','modern','warm'] as $style) {
        $brief=new SiteBrief($product,'Empresa de teste','Soluções de exemplo',
            'Demonstração privada com conteúdo fictício, sem captação de dados.',
            'Conhecer serviços',['Transparência','Organização'],$style);
        $artifact=$renderer->render($brief);
        $manifest=new ReleaseManifest('tenant-f75','site-f75',
            'release-f75-'.$product.'-'.$style,$artifact->digest);
        $bundle=new SyntheticReleaseBundle($manifest,$artifact);
        $files=$bundle->files;
        $raw=$files['release-manifest.json'];

        f75Check(SyntheticReleaseBundle::verifyFiles($files,$manifest),
            "$product/$style: canonical generated manifest passes");
        $meta=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        $encoded=json_encode($meta,
            JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
        )."\n";
        f75Check(hash_equals($raw,$encoded),
            "$product/$style: expected canonical bytes match builder");

        // Malformed but semantically equivalent manifests can disagree across
        // parser implementations. They must fail even if hashes match HTML.
        $mutations=[
            'minified'=>json_encode($meta,JSON_THROW_ON_ERROR),
            'leading_blank'=>"\n".$raw,
            'trailing_blank'=>$raw."\n",
            'trailing_space'=>$raw." ",
            'duplicate_publishable_false'=>str_replace(
                '    "publishable": false,',
                '    "publishable": false,'."\n".'    "publishable": false,',
                $raw
            ),
            'duplicate_publishable_conflicting'=>str_replace(
                '    "publishable": false,',
                '    "publishable": true,'."\n".'    "publishable": false,',
                $raw
            ),
            'duplicate_release_ref'=>str_replace(
                '    "releaseRef": "'.$manifest->releaseRef.'",',
                '    "releaseRef": "fake-ref",'."\n".
                '    "releaseRef": "'.$manifest->releaseRef.'",',
                $raw
            ),
            'unicode_escape_in_key'=>str_replace(
                '"tenantRef":','"\u0074enantRef":',$raw
            ),
            'different_indent'=>str_replace('    "tenantRef":', '  "tenantRef":',$raw),
        ];
        foreach($mutations as $label=>$changed) {
            f75Check($raw!==$changed,
                "$product/$style/$label: mutation produced distinct bytes");
            $modified=$files;
            $modified['release-manifest.json']=$changed;
            f75Check(!SyntheticReleaseBundle::verifyFiles($modified,$manifest),
                "$product/$style/$label: noncanonical manifest denied");
        }
        f75Check(hash('sha256',$files['index.html'])===$artifact->digest,
            "$product/$style: HTML digest is still content-addressed");
        f75Check($meta['publishable']===false &&
            $meta['officialClientAcceptance']===false,
            "$product/$style: release remains nonpublishable and unapproved");
    }
}
echo "\n$total F1-75 bundle canonicality tests passed. No official acceptance or release.\n";
