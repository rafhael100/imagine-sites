<?php
declare(strict_types=1);

use Imagine\Sites\Production\{LocalSiteRendering, OnepageBuyerPrivatePreviewCandidate, OnepageExactFinalAcceptanceReadCandidate};

// CI-only loopback transport exercise. Synthetic cookie is NOT native Portal
// authentication, and the copied metadata is NOT an operational authority.
define('ONEPAGE_HTTP_R04_FIXTURES_ONLY', true);
require __DIR__.'/onepage-final-acceptance-artifact-drift-offline.php';

$checks = 0;
$check = static function (bool $passed, string $label) use (&$checks): void {
    if (!$passed) throw new RuntimeException('R04_REFUSED: '.$label);
    ++$checks;
};
$denied = static function (callable $operation, string $label) use ($check): void {
    $refused = false;
    ob_start();
    try {
        $operation();
    } catch (RuntimeException $e) {
        $refused = $e->getMessage() === 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED'
            && $e->getPrevious() === null;
    }
    $output = ob_get_clean();
    $check($refused && $output === '', $label);
};

require_once __DIR__.'/fixtures/onepage-r04-loopback.php';
withOnepageR04Loopback($originalArtifact->html, static function (Closure $readHttp, string $directory) use (
    $check, $denied, &$checks, $originalArtifact, $initial, $brief, $editorial, $json, $plan, $presentation
): void {
    $available = false;
    for ($try=0; $try<40; ++$try) {
        $ping = $readHttp('/private-onepage', null);
        if ($ping['status'] === 403) { $available=true; break; }
        usleep(50000);
    }
    $check($available, 'loopback endpoint responds only on local interface');
    $check($readHttp('/private-onepage', null)['status'] === 403, 'absent session denied');
    $check($readHttp('/private-onepage', 'synthetic-buyer-two-session')['status'] === 403,
        'another buyer from same company denied');
    $check($readHttp('/other-path', 'synthetic-buyer-one-session')['status'] === 404,
        'unrelated route denied');

    $firstGet = $readHttp('/private-onepage', 'synthetic-buyer-one-session');
    $check($firstGet['status'] === 200 && $firstGet['noStore'] && $firstGet['html'],
        'authorized fixture response is private and noncacheable');
    $check($firstGet['bytes'] === $originalArtifact->html &&
        hash('sha256', $firstGet['bytes']) === $originalArtifact->digest,
        'bytes captured over loopback HTTP equal reviewed renderer digest');

    $httpReads = 0;
    $buildGate = static function (array $state, ?string $session, ?Closure $afterRead = null) use (
        $readHttp, &$httpReads
    ): OnepageExactFinalAcceptanceReadCandidate {
        $preview = new OnepageBuyerPrivatePreviewCandidate(
            static fn()=>$state['record'],
            static fn()=>$state['head'],
            static fn()=>$state['confirmation'],
            static fn()=>$state['review'],
        );
        return new OnepageExactFinalAcceptanceReadCandidate(
            $preview, static fn()=>$state['release'],
            static fn()=>$state['acceptance'],
            static function () use ($readHttp, &$httpReads, $state, $session, $afterRead): array {
                ++$httpReads;
                $response = $readHttp('/private-onepage', $session);
                if ($response['status'] !== 200 || !$response['noStore'] ||
                    !$response['html'] || $response['bytes'] === '') {
                    throw new RuntimeException('HTTP_WITNESS_DENIED');
                }
                // Only bytes come from independent HTTP transport. The rest
                // remain explicitly synthetic fixtures, never official receipts.
                if ($afterRead !== null) $afterRead($httpReads);
                return array_replace($state['presentation'], [
                    'servedHtmlBytes'=>$response['bytes'],
                ]);
            },
        );
    };
    $accept = static fn(OnepageExactFinalAcceptanceReadCandidate $gate, string $buyer='buyer-one') =>
        $gate->observe($buyer, $brief->tenantRef, $brief->orderRef, $brief->siteRef, $brief->sourceVersion);

    $resultA = $accept($buildGate($initial, 'synthetic-buyer-one-session'));
    $check($resultA['clientFinalAccepted'] === true &&
        $resultA['publicationAuthorized'] === false &&
        $resultA['htmlSha256'] === $originalArtifact->digest,
        'existing read gate consumes two independent HTTP byte observations without publishing');
    $check($httpReads === 2, 'HTTP bytes were fetched twice rather than trusting click metadata');
    $denied(static fn()=>$accept($buildGate($initial, 'synthetic-buyer-one-session'), 'buyer-two'),
        'same company other buyer denied by production read gate');
    $denied(static fn()=>$accept($buildGate($initial, null)),
        'synthetic session missing refuses the read gate');

    // The first authenticated GET observes A, but the private origin switches
    // its bytes before the second independent read during the same decision.
    $httpReads = 0;
    $denied(static fn()=>$accept($buildGate(
        $initial, 'synthetic-buyer-one-session',
        static function (int $readNumber) use ($directory, $originalArtifact): void {
            if ($readNumber === 1) {
                file_put_contents($directory.'/preview.html', $originalArtifact->html.'<!-- mid-read -->', LOCK_EX);
            }
        },
    )), 'HTTP bytes changed between two witness reads');
    $check($httpReads === 2, 'two real HTTP GETs detect the mid-decision drift');

    file_put_contents($directory.'/preview.html', $originalArtifact->html.'<!-- changed -->', LOCK_EX);
    $denied(static fn()=>$accept($buildGate($initial, 'synthetic-buyer-one-session')),
        'HTTP bytes changed while declared digest and final click stayed unchanged');

    $copyB = json_decode($editorial, true, 16, JSON_THROW_ON_ERROR);
    $copyB['headline'] = 'Segunda redação fictícia HTTP do mesmo pedido';
    $editorialB = $json($copyB);
    $artifactB = LocalSiteRendering::render($editorialB);
    $check($artifactB->digest !== $originalArtifact->digest, 'second renderer HTML has different SHA');
    $planB = $plan;
    $planB['releaseRef'] = 'release-r04-b';
    $planB['renderInput']['sizeBytes'] = strlen($editorialB);
    $planB['renderInput']['sha256'] = hash('sha256', $editorialB);
    $planB['expectedArtifact'] = ['sha256'=>$artifactB->digest, 'sizeBytes'=>$artifactB->sizeBytes];
    $v2 = $initial;
    $v2['record']['editorial'] = $editorialB;
    $v2['record']['plan'] = $json($planB);
    $v2['release'] = array_replace($initial['release'], [
        'releaseRef'=>'release-r04-b', 'artifactEpoch'=>2,
        'htmlSha256'=>$artifactB->digest, 'reviewApprovalRef'=>'artifact-review-r04-b',
    ]);
    $v2['presentation'] = array_replace($initial['presentation'], [
        'presentationRef'=>'presentation-r04-b', 'releaseRef'=>'release-r04-b',
        'artifactEpoch'=>2, 'htmlSha256'=>$artifactB->digest,
    ]);
    file_put_contents($directory.'/preview.html', $artifactB->html, LOCK_EX);
    $denied(static fn()=>$accept($buildGate($v2, 'synthetic-buyer-one-session')),
        'previous final acceptance cannot authorize next HTML release');
    $v2['acceptance'] = $v2['release'] + [
        'buyerRef'=>'buyer-one', 'acceptanceRef'=>'final-accept-r04-b',
        'presentationRef'=>'presentation-r04-b', 'explicitClick'=>true, 'revoked'=>false,
    ];
    $resultB = $accept($buildGate($v2, 'synthetic-buyer-one-session'));
    $check($resultB['htmlSha256'] === $artifactB->digest &&
        $resultB['finalAcceptanceRef'] === 'final-accept-r04-b' &&
        $resultB['canPublish'] === false,
        'fresh synthetic v2 receipt matches separately served v2 HTTP bytes');
    $denied(static fn()=>$accept($buildGate($v2, 'synthetic-buyer-two-session')),
        'v2 other buyer session denied');

    echo 'ONEPAGE R04 LOOPBACK HTTP PASS: '.$checks." synthetic checks; NO-GO.\n";
});
