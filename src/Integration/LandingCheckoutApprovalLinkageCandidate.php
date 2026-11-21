<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use RuntimeException;
use Throwable;

/**
 * Laboratory-only join of four independently supplied CURRENT read models.
 * This class is NOT a source of contractual, briefing, browser or publication
 * authority. No source implementations or operational service bindings exist.
 */
interface LandingAuthenticatedBuyerReadSource
{
    /** @return array<string,mixed>|null */
    public function currentBuyerForSession(string $opaqueSessionRef): ?array;
}

interface LandingPurchaseOrderReadSource
{
    /** @return array<string,mixed>|null */
    public function currentOrder(string $orderRef): ?array;
}

interface LandingExactTermsAcceptanceReadSource
{
    /** @return array<string,mixed>|null */
    public function currentAcceptance(string $tenantRef, string $orderRef): ?array;
}

interface LandingApprovedBriefReadSource
{
    /** @return array<string,mixed>|null */
    public function currentApprovedBrief(string $tenantRef, string $orderRef, string $siteRef): ?array;
}

final class LandingCheckoutApprovalLinkageCandidate
{
    public function __construct(
        private readonly LandingAuthenticatedBuyerReadSource $sessions,
        private readonly LandingPurchaseOrderReadSource $orders,
        private readonly LandingExactTermsAcceptanceReadSource $terms,
        private readonly LandingApprovedBriefReadSource $briefs,
    ) {}

    /**
     * Strict linkage witness, intentionally NOT permission to return HTML or
     * treat the contract as legally accepted. Requires a separate real Portal
     * writer, browser presentation/click proof, and verified session resolver.
     *
     * @return array<string,mixed>
     */
    public function check(string $orderRef, string $opaqueSessionRef): array
    {
        try {
            self::ref($orderRef);
            self::ref($opaqueSessionRef);
            $first = $this->read($orderRef, $opaqueSessionRef);
            $second = $this->read($orderRef, $opaqueSessionRef);
            if (!hash_equals($first, $second)) {
                throw new RuntimeException('Changed witnesses');
            }
            $snapshot = json_decode($first, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) {
                throw new RuntimeException('Invalid witness');
            }
            return [
                'schemaVersion' => 'sites.landing.checkout-approval-linkage-candidate.v1',
                'status' => 'candidate_correlated_not_authorized',
                'tenantRef' => $snapshot['tenantRef'],
                'orderRef' => $snapshot['orderRef'],
                'siteRef' => $snapshot['siteRef'],
                'contractRevision' => $snapshot['contractRevision'],
                'termsSha256' => $snapshot['termsSha256'],
                'briefRevision' => $snapshot['briefRevision'],
                'briefSha256' => $snapshot['briefSha256'],
                'releaseGate' => 'NO_GO',
                'contractLegallyAccepted' => false,
                'buyerBrowserGetProven' => false,
                'briefingOfficialApprovalProven' => false,
                'previewAuthorized' => false,
                'publishAuthorized' => false,
            ];
        } catch (Throwable) {
            // Never leak a private order, session, review, or approval reference.
            throw new RuntimeException('LANDING_CHECKOUT_LINKAGE_DENIED');
        }
    }

    private function read(string $orderRef, string $opaqueSessionRef): string
    {
        $session = self::shape($this->sessions->currentBuyerForSession($opaqueSessionRef),
            ['sessionRef', 'tenantRef', 'buyerRef', 'state']);
        $order = self::shape($this->orders->currentOrder($orderRef),
            ['tenantRef', 'buyerRef', 'orderRef', 'siteRef', 'productCode']);
        foreach ([$session['sessionRef'], $session['tenantRef'], $session['buyerRef'],
                  $order['tenantRef'], $order['buyerRef'], $order['orderRef']] as $ref) {
            self::ref($ref);
        }
        self::site($order['siteRef']);
        if ($session['sessionRef'] !== $opaqueSessionRef ||
            $session['state'] !== 'active' || $order['orderRef'] !== $orderRef ||
            $order['productCode'] !== 'landing_page' ||
            !hash_equals($session['tenantRef'], $order['tenantRef']) ||
            !hash_equals($session['buyerRef'], $order['buyerRef'])) {
            throw new RuntimeException('No matching buyer/order');
        }

        $terms = self::shape($this->terms->currentAcceptance($order['tenantRef'], $orderRef), [
            'tenantRef', 'buyerRef', 'orderRef', 'siteRef', 'contractRef',
            'contractRevision', 'termsSha256', 'presentedSha256', 'acceptedSha256',
            'presentationRef', 'acceptanceRef', 'acceptanceState', 'expresslyAccepted',
        ]);
        $brief = self::shape($this->briefs->currentApprovedBrief(
            $order['tenantRef'], $orderRef, $order['siteRef']), [
            'tenantRef', 'orderRef', 'siteRef', 'contractRef', 'contractRevision',
            'briefRevision', 'briefSha256', 'approvalRef', 'approvalEpoch', 'approvalState',
        ]);
        foreach ([$terms['tenantRef'], $terms['buyerRef'], $terms['orderRef'],
                  $terms['contractRef'], $terms['presentationRef'], $terms['acceptanceRef'],
                  $brief['tenantRef'], $brief['orderRef'], $brief['contractRef'],
                  $brief['approvalRef']] as $ref) {
            self::ref($ref);
        }
        self::site($terms['siteRef']);
        self::site($brief['siteRef']);
        foreach (['termsSha256', 'presentedSha256', 'acceptedSha256'] as $key) {
            self::digest($terms[$key]);
        }
        self::digest($brief['briefSha256']);
        foreach ([$terms['contractRevision'], $brief['contractRevision'],
                  $brief['briefRevision'], $brief['approvalEpoch']] as $rev) {
            if (!is_int($rev) || $rev < 1 || $rev > 9007199254740991) {
                throw new RuntimeException('Invalid version');
            }
        }
        if ($terms['acceptanceState'] !== 'accepted' ||
            $terms['expresslyAccepted'] !== true ||
            $brief['approvalState'] !== 'approved' ||
            $terms['tenantRef'] !== $order['tenantRef'] ||
            $brief['tenantRef'] !== $order['tenantRef'] ||
            $terms['buyerRef'] !== $order['buyerRef'] ||
            $terms['orderRef'] !== $orderRef || $brief['orderRef'] !== $orderRef ||
            $terms['siteRef'] !== $order['siteRef'] ||
            $brief['siteRef'] !== $order['siteRef'] ||
            $terms['contractRef'] !== $brief['contractRef'] ||
            $terms['contractRevision'] !== $brief['contractRevision'] ||
            !hash_equals($terms['termsSha256'], $terms['presentedSha256']) ||
            !hash_equals($terms['termsSha256'], $terms['acceptedSha256'])) {
            throw new RuntimeException('Unlinked or stale evidence');
        }
        // Only a private, bounded fingerprint is compared across independent
        // reads. No HTML, briefing bytes, terms, or customer details are logged.
        return json_encode([
            'tenantRef' => $order['tenantRef'],
            'buyerRef' => $order['buyerRef'],
            'sessionRef' => $session['sessionRef'],
            'orderRef' => $orderRef,
            'siteRef' => $order['siteRef'],
            'contractRef' => $terms['contractRef'],
            'contractRevision' => $terms['contractRevision'],
            'termsSha256' => $terms['termsSha256'],
            'presentationRef' => $terms['presentationRef'],
            'acceptanceRef' => $terms['acceptanceRef'],
            'briefRevision' => $brief['briefRevision'],
            'briefSha256' => $brief['briefSha256'],
            'approvalRef' => $brief['approvalRef'],
            'approvalEpoch' => $brief['approvalEpoch'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param mixed $row @param list<string> $expected @return array<string,mixed> */
    private static function shape(mixed $row, array $expected): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new RuntimeException('Missing source');
        }
        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Untrusted source shape');
        }
        return $row;
    }

    private static function ref(mixed $ref): void
    {
        if (!is_string($ref) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref) !== 1) {
            throw new RuntimeException('Invalid ref');
        }
    }

    private static function site(mixed $ref): void
    {
        if (!is_string($ref) || preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $ref) !== 1) {
            throw new RuntimeException('Invalid site');
        }
    }

    private static function digest(mixed $hash): void
    {
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new RuntimeException('Invalid digest');
        }
    }
}
