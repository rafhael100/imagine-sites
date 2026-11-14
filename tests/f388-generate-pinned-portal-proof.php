<?php

declare(strict_types=1);

/**
 * F1-388 disposable CI-only: load pinned Portal F1-387 original code, issue
 * a fictitious Ed25519 proof with a fresh ephemeral key and store only the
 * public key + fictional proof for the private HTTPS server. Never persists
 * the Ed25519 private key.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('SITES_F388_MTLS_LAB')!=='1') {
    fwrite(STDERR,"F1-388 requires explicit disposable CI opt-in.\n");
    exit(1);
}
$portal=(string)getenv('F388_PORTAL_CHECKOUT');
$dir=(string)getenv('F388_FIXTURE_DIR');
if (!is_dir($dir) || !is_dir($portal) ||
    !is_file($portal.'/apps/portal/app/Domain/SitesIntegration/PortalEd25519EntitlementIssuer.php')) {
    throw new RuntimeException('Pinned source or ephemeral output path missing.');
}
spl_autoload_register(static function(string $class)use($portal):void {
    $prefix='App\\Domain\\SitesIntegration\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name))
        throw new RuntimeException('Unsafe Portal class request.');
    $file=$portal.'/apps/portal/app/Domain/SitesIntegration/'.$name.'.php';
    if(!is_file($file))throw new RuntimeException('Pinned Portal class missing.');
    require $file;
});

use App\Domain\SitesIntegration\PortalCurrentSettlementSource;
use App\Domain\SitesIntegration\PortalCurrentRevocationEpochSource;
use App\Domain\SitesIntegration\PortalSettlementEvidence;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\PortalEd25519EntitlementIssuer;

final class F388FictionalPortalContract implements PortalSiteContractSource {
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot {
        return $orderRef==='order-f388' ?
            new SiteContractSnapshot(
                'tenant-f388','order-f388','contract-f388',1,'onepage'
            ):null;
    }
}
final class F388FictionalSettlement implements PortalCurrentSettlementSource {
    public function findCurrentSettledOrder(string $orderRef):?PortalSettlementEvidence {
        return $orderRef==='order-f388' ?
            new PortalSettlementEvidence(
                'tenant-f388','order-f388','contract-f388',1,'onepage',
                'psp-f388-fictional-settled',
                hash('sha256','F388-FICTIONAL-NO-REAL-PAYMENT')
            ):null;
    }
}
final class F388FictionalRevocation implements PortalCurrentRevocationEpochSource {
    public function readCurrentActiveEpoch(
        string $tenantRef,string $orderRef,string $contractRef,int $contractRevision
    ):?int {
        return $tenantRef==='tenant-f388' && $orderRef==='order-f388' &&
            $contractRef==='contract-f388' && $contractRevision===1?9:null;
    }
}
$now=time();
$keypair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keypair);
$public=bin2hex(sodium_crypto_sign_publickey($keypair));
$proof=(new PortalEd25519EntitlementIssuer(
    new F388FictionalPortalContract(),
    new F388FictionalSettlement(),
    new F388FictionalRevocation()
))->issue('order-f388','operation-f388','idem-f388',$now,$secret,110);
sodium_memzero($secret);
if (file_put_contents(
    $dir.'/proof.json',
    json_encode($proof,JSON_THROW_ON_ERROR|
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    LOCK_EX
)===false || file_put_contents($dir.'/portal-public.hex',$public,LOCK_EX)===false ||
    file_put_contents($dir.'/mode.txt','ok',LOCK_EX)===false) {
    throw new RuntimeException('Cannot write fictional mTLS proof assets.');
}
echo "F1-388: pinned Portal F1-387 produced a short-lived fictional Ed25519 proof; no signer key persisted.\n";
