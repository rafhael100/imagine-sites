<?php
declare(strict_types=1);
use OnepageSitesTerminalDraft\SitesTlsAdmission;

$phase='INPUT';
ini_set('display_errors','0');
ini_set('log_errors','0');
try {
    if (PHP_SAPI!=='cli' || $argc!==6 || $argv[3]===$argv[4]) throw new RuntimeException();
    [,$run,$outside,$control,$body,$sites]=$argv;
    $root=__DIR__;
    foreach ([
        'Stage3ReceiptChannel.php'=>'ee5e124be0521f44af7114ecd291c262ae544e6e448371d34f065973536426ba',
        'TlsAdmission.php'=>'c831c002226587c4cf19e15e2f7c39116b895e48276f98667d45a0ee47b96676',
    ] as $file=>$sha) {
        $path=$root.'/'.$file;
        if (realpath($path)!==$path || !is_file($path) || hash_file('sha256',$path)!==$sha) throw new RuntimeException();
        require $path;
    }
    if ($sites==='' || realpath($sites)!==$sites || !is_dir($sites) ||
        !is_file($sites.'/apps/sites/vendor/autoload.php')) throw new RuntimeException();

    $phase='ADMISSION';
    $admission=SitesTlsAdmission::receive($run,$outside,$control,$body);
    $writer=$admission->openBootstrap(false);
    $observer=$admission->openBootstrap(true);
    if ($writer===$observer || $admission->assertBootstrap($writer)===$admission->assertBootstrap($observer)) {
        throw new RuntimeException();
    }
    $phase='SCENARIO';
    $scenarioPath=$root.'/scenario.php';
    // Exact scenario, schemas, fixture seeds and #541 source/vendor must be
    // pinned by the outer review before this launcher is authorized to run.
    if (realpath($scenarioPath)!==$scenarioPath || !is_file($scenarioPath)) throw new RuntimeException();
    echo "ONEPAGE_TERMINAL_PDO_ADMITTED_OK\n";
    ob_start();
    $scenario=require $scenarioPath;
    if (!$scenario instanceof Closure || $scenario($admission,$sites,$writer,$observer,$run)!==true ||
        ob_get_clean()!=='' || $writer->inTransaction() || $observer->inTransaction() ||
        $admission->assertBootstrap($writer)===$admission->assertBootstrap($observer)) throw new RuntimeException();
    $writer=null; $observer=null;
    echo "ONEPAGE_TERMINAL_REVOKE_FIRST_OK\n";
} catch (Throwable) {
    while (ob_get_level()>0) ob_end_clean();
    fwrite(STDERR,'ONEPAGE_TERMINAL_ENTRY_REFUSED '.$phase."\n");
    exit(1);
}
