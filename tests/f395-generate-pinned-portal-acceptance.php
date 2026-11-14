<?php

declare(strict_types=1);

/** Real pinned Portal F1-394 issuer with ephemeral fictional consent; CI only. */
if(PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
   getenv('SITES_F395_MTLS_LAB')!=='1') {
    fwrite(STDERR,"F1-395 fixture runs only in disposable CI.\n");
    exit(1);
}
$portal=(string)getenv('F395_PORTAL_CHECKOUT');
$dir=(string)getenv('F388_FIXTURE_DIR');
if(!is_dir($dir) || !is_file($portal.
    '/apps/portal/app/Domain/SitesIntegration/PortalEd25519ClientAcceptanceIssuer.php')) {
    throw new RuntimeException('Pinned Portal issuer or fixture dir missing.');
}
spl_autoload_register(static function(string $class)use($portal):void {
    $prefix='App\\Domain\\SitesIntegration\\';
    if (!str_starts_with($class,$prefix)) return;
    $short=substr($class,strlen($prefix));
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
        throw new RuntimeException('Unsafe class in fixture.');
    }
    require_once $portal.'/apps/portal/app/Domain/SitesIntegration/'.$short.'.php';
});

use App\Domain\SitesIntegration\PortalCurrentClientAcceptanceSource;
use App\Domain\SitesIntegration\PortalClientAcceptanceSnapshot;
use App\Domain\SitesIntegration\PortalEd25519ClientAcceptanceIssuer;

final class F395FakeConsentSource implements PortalCurrentClientAcceptanceSource
{
    public function readCurrent(
        string $siteRef,string $releaseRef,int $editorialRevision
    ):?PortalClientAcceptanceSnapshot {
        if ($siteRef!=='site-f395' || $releaseRef!=='release-f395' ||
            $editorialRevision!==5) return null;
        return new PortalClientAcceptanceSnapshot(
            'tenant-f395','site-f395','release-f395',
            hash('sha256','F395 FICTIONAL APPROVED HTML'),
            5,'acceptance-f395-ci-001',12,time()-30,
            true,true,true,
        );
    }
}
$keypair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keypair);
$public=bin2hex(sodium_crypto_sign_publickey($keypair));
$proof=(new PortalEd25519ClientAcceptanceIssuer(
    new F395FakeConsentSource()
))->issue('site-f395','release-f395',5,time(),$secret,60);
sodium_memzero($secret);
if (
    file_put_contents($dir.'/proof.json',
        json_encode($proof,JSON_THROW_ON_ERROR|
            JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        LOCK_EX)===false ||
    file_put_contents($dir.'/portal-public.hex',$public,LOCK_EX)===false ||
    file_put_contents($dir.'/mode.txt','ok',LOCK_EX)===false
) {
    throw new RuntimeException('Cannot prepare F1-395 ephemeral receipt.');
}
echo "Pinned Portal F1-394 issuer emitted ephemeral fake acceptance receipt.\n";
