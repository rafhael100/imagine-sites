<?php

declare(strict_types=1);

use App\Integration\StoredOnepageReviewSupersession;

// Loaded only from the two-revision PG18 contract, under the explicit new CI
// flag. The preceding fixture has already stored both Portal-generated inputs.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('SITES_ONEPAGE_SUPERSESSION_CI') !== '1' ||
    getenv('SITES_ONEPAGE_SUPERSESSION_READBACK_CI') !== '1' ||
    !isset($suProof, $suService, $suPrevious, $suOld, $suOldPlan,
        $suNext, $suNextPlan, $suForeign, $suChecks, $suOk, $suDeny, $suNoWrite, $bapEditorial)) {
    throw new RuntimeException('Onepage supersession readback requires an isolated PG18 fixture');
}

$srStart = $suChecks;
$srVerify = static function (
    array $claim,
    array $previous,
    array $previousExport,
    string $previousPlan,
    array $nextExport,
    string $nextPlan,
    ?StoredOnepageReviewSupersession $service = null,
) use ($suService, $bapEditorial): void {
    ($service ?? $suService)->assertCurrent(
        $claim, $previous, $previousExport, $previousPlan, $bapEditorial,
        $nextExport, $nextPlan, $bapEditorial,
    );
};

// The successful readback itself grants nothing and must be repeatable.
$srVerify($suProof, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan);
$suOk(true, 'current supersession receipt verified by tenant-scoped SQL');
$srVerify($suProof, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan);
$suOk(true, 'idempotent current receipt readback');
$suNoWrite('positive current receipt readback');

$srWrongHash = $suProof;
$srWrongHash['supersessionSha256'] = str_repeat('0', 64);
$suDeny(static fn() => $srVerify(
    $srWrongHash, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'untrusted supersession digest');
$srTampered = $suProof;
$srTampered['supersessionBytes'] .= " ";
$suDeny(static fn() => $srVerify(
    $srTampered, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'untrusted supersession bytes');
$srTampered = $suProof;
$srBody = json_decode($srTampered['supersessionBytes'], true, 16, JSON_THROW_ON_ERROR);
$srBody['internalReviewApproved'] = true;
$srTampered['supersessionBytes'] = bapJson($srBody);
$srTampered['supersessionSha256'] = hash('sha256',
    "SITES_ONEPAGE_REVIEW_SUPERSESSION_CANDIDATE_V1\0".$srTampered['supersessionBytes']);
$suDeny(static fn() => $srVerify(
    $srTampered, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'rehashed counterfeit reviewer approval');
$srTampered = $suProof;
$srBody = json_decode($srTampered['supersessionBytes'], true, 16, JSON_THROW_ON_ERROR);
$srBody['canPublish'] = true;
$srTampered['supersessionBytes'] = bapJson($srBody);
$srTampered['supersessionSha256'] = hash('sha256',
    "SITES_ONEPAGE_REVIEW_SUPERSESSION_CANDIDATE_V1\0".$srTampered['supersessionBytes']);
$suDeny(static fn() => $srVerify(
    $srTampered, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'rehashed counterfeit publishing flag');
$srTampered = $suProof;
$srBody = json_decode($srTampered['supersessionBytes'], true, 16, JSON_THROW_ON_ERROR);
$srBody['nextBriefRevision'] = 3;
$srTampered['supersessionBytes'] = bapJson($srBody);
$srTampered['supersessionSha256'] = hash('sha256',
    "SITES_ONEPAGE_REVIEW_SUPERSESSION_CANDIDATE_V1\0".$srTampered['supersessionBytes']);
$suDeny(static fn() => $srVerify(
    $srTampered, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'rehashed counterfeit successor revision');
$suNoWrite('altered receipts');

$srExtra = $suProof;
$srExtra['portalApprovalAuthenticated'] = true;
$suDeny(static fn() => $srVerify(
    $srExtra, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'forged authority field in envelope');
$srMissing = ['supersessionSha256' => $suProof['supersessionSha256']];
$suDeny(static fn() => $srVerify(
    $srMissing, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'missing current evidence');
$srInvalid = ['supersessionBytes' => 42, 'supersessionSha256' => false];
$suDeny(static fn() => $srVerify(
    $srInvalid, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan),
    'non-string receipt');
$suDeny(static fn() => $srVerify(
    $suProof, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan, $suForeign),
    'other tenant cannot revalidate a coherent receipt');
$srModifiedNext = $suNext;
$srModifiedNext['sourceBytes'] .= " ";
$srModifiedNext['payloadSha256'] = hash('sha256', $srModifiedNext['sourceBytes']);
$suDeny(static fn() => $srVerify(
    $suProof, $suPrevious, $suOld, $suOldPlan, $srModifiedNext, $suNextPlan),
    'source drift despite an unchanged receipt');
$suDeny(static fn() => $srVerify(
    $suProof, $suPrevious, $suOld, $suOldPlan, $suOld, $suOldPlan),
    'receipt replayed against the superseded version');
$suNoWrite('malformed and cross-tenant receipts');

$srVerify($suProof, $suPrevious, $suOld, $suOldPlan, $suNext, $suNextPlan);
$suOk(true, 'valid readback still succeeds after adversarial inputs');
$suNoWrite('final current receipt replay');

echo 'ONEPAGE SUPERSESSION CURRENT READBACK PG18 PASS: '.($suChecks-$srStart).
    " checks; evidence only, no official current Portal head or publication.\n";
