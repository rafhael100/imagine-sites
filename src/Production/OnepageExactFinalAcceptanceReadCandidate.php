<?php
declare(strict_types=1);

namespace Imagine\Sites\Production;

use Closure;
use RuntimeException;
use Throwable;

/**
 * CI-only, NO-RUNTIME-BIND read gate. This does not create an acceptance or
 * authenticate a buyer. The four ports must be backed by separately authorized
 * durable sources and a server-verified buyer principal before operational use.
 * The presentation witness MUST be from the independently authenticated delivery
 * observed by the buyer, never from the submitted click or accepted receipt.
 * Its HTML bytes must be independently captured from that presentation, not
 * copied from a client-controlled digest, receipt, or preview fixture.
 * A private preview alone NEVER grants final acceptance or publication.
 */
final readonly class OnepageExactFinalAcceptanceReadCandidate
{
    private const DENIED = 'ONEPAGE_EXACT_FINAL_ACCEPTANCE_DENIED';

    /**
     * @param ?Closure $currentRelease (tenant,order,site) -> current reviewed HTML head
     * @param ?Closure $currentAcceptance (tenant,order,site,buyer) -> durable exact click receipt
     * @param ?Closure $currentPresentation (tenant,order,site,buyer,presentationRef) -> independent exact presentation
     */
    public function __construct(
        private ?OnepageBuyerPrivatePreviewCandidate $preview = null,
        private ?Closure $currentRelease = null,
        private ?Closure $currentAcceptance = null,
        private ?Closure $currentPresentation = null,
    ) {}

    /**
     * $viewerRef must originate in server-side verified identity; never a query
     * parameter. Read-after-read detects observed races; it is NOT a SQL lock,
     * transactional snapshot, or publication authorization.
     */
    public function observe(
        string $viewerRef, string $tenantRef, string $orderRef,
        string $siteRef, int $sourceVersion,
    ): array {
        try {
            if ($this->preview === null || $this->currentRelease === null ||
                $this->currentAcceptance === null || $this->currentPresentation === null ||
                !self::ref($viewerRef) ||
                !self::ref($tenantRef) || !self::ref($orderRef) ||
                preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $siteRef) !== 1 ||
                $sourceVersion < 0 || $sourceVersion > 9007199254740991) {
                throw new RuntimeException();
            }
            $head = $this->readHead($tenantRef, $orderRef, $siteRef, $sourceVersion, $viewerRef);
            $receipt = $this->readReceipt($tenantRef, $orderRef, $siteRef, $viewerRef, $head);

            // The independently stored presentation includes private HTML bytes.
            // First validate current buyer rights and reviewer approval through
            // the fail-closed preview; do not read servedHtmlBytes for a revoked
            // or tampered briefing, even when a stale final click still exists.
            // Reuse the already-reviewed exact briefing + human witness +
            // renderer checks. An accepted receipt cannot make a stale preview
            // current or override a rejected reviewer/rights witness.
            $preview = $this->preview->render(
                $viewerRef, $tenantRef, $orderRef, $siteRef, $sourceVersion,
            );
            if (!is_array($preview) || !is_string($preview['html'] ?? null) ||
                !is_string($preview['sha256'] ?? null) ||
                !hash_equals($head['htmlSha256'], $preview['sha256']) ||
                !hash_equals($head['htmlSha256'], hash('sha256', $preview['html'])) ||
                ($preview['sourceVersion'] ?? null) !== $head['sourceVersion'] ||
                ($preview['briefRevision'] ?? null) !== $head['briefRevision'] ||
                ($preview['privatePreview'] ?? null) !== true ||
                ($preview['clientFinalAccepted'] ?? null) !== false ||
                ($preview['canPublish'] ?? null) !== false ||
                ($preview['publicationAuthorized'] ?? null) !== false) {
                throw new RuntimeException();
            }

            // Only after the preview passes current human/source validation
            // may we read the independent private presentation bytes. This
            // remains read-only and does NOT authenticate a browser or lock
            // either source transactionally.
            $presentation = $this->readPresentation(
                $tenantRef, $orderRef, $siteRef, $viewerRef, $head, $receipt,
            );
            // Metadata alone is insufficient: the independent observation must
            // match the exact bytes rendered from the reviewed source.
            if ($presentation['servedHtmlBytes'] !== $preview['html']) {
                throw new RuntimeException();
            }

            $freshHead = $this->readHead($tenantRef, $orderRef, $siteRef, $sourceVersion, $viewerRef);
            $freshReceipt = $this->readReceipt($tenantRef, $orderRef, $siteRef, $viewerRef, $freshHead);
            // An editorial revocation or withdrawn rights may happen while the
            // FIRST independent presentation bytes are being read. Reread the
            // already-existing fail-closed current briefing/rights/reviewer
            // evidence before consulting private HTML bytes a SECOND time.
            // This does not make independent sources transactional or CAS-safe.
            $freshPreview = $this->preview->render(
                $viewerRef, $tenantRef, $orderRef, $siteRef, $sourceVersion,
            );
            if ($freshPreview !== $preview) throw new RuntimeException();
            $freshPresentation = $this->readPresentation(
                $tenantRef, $orderRef, $siteRef, $viewerRef, $freshHead, $freshReceipt,
            );
            if ($freshHead !== $head || $freshReceipt !== $receipt ||
                $freshPresentation !== $presentation) throw new RuntimeException();

            return [
                'releaseRef' => $head['releaseRef'],
                'artifactEpoch' => $head['artifactEpoch'],
                'htmlSha256' => $head['htmlSha256'],
                'sourceVersion' => $head['sourceVersion'],
                'briefRevision' => $head['briefRevision'],
                'finalAcceptanceRef' => $receipt['acceptanceRef'],
                'clientFinalAccepted' => true,
                'privatePreview' => true,
                'publicationAuthorized' => false,
                'canPublish' => false,
            ];
        } catch (Throwable) {
            // Never disclose bytes, identities, digests, or database exceptions.
            throw new RuntimeException(self::DENIED);
        }
    }

    private function readHead(
        string $tenant, string $order, string $site, int $version, string $viewer,
    ): array {
        $head = ($this->currentRelease)($tenant, $order, $site);
        $keys = ['tenantRef','orderRef','siteRef','sourceVersion','briefRevision',
            'releaseRef','artifactEpoch','htmlSha256','reviewerRef','reviewApprovalRef','approved'];
        if (!self::keys($head, $keys) ||
            $head['tenantRef'] !== $tenant || $head['orderRef'] !== $order ||
            $head['siteRef'] !== $site || $head['sourceVersion'] !== $version ||
            !is_int($head['briefRevision']) || $head['briefRevision'] < 1 ||
            !is_int($head['artifactEpoch']) || $head['artifactEpoch'] < 1 ||
            !self::ref($head['releaseRef']) || !self::ref($head['reviewerRef']) ||
            !self::ref($head['reviewApprovalRef']) || $head['reviewerRef'] === $viewer ||
            !self::digest($head['htmlSha256']) || $head['approved'] !== true) {
            throw new RuntimeException();
        }
        return $head;
    }

    private function readReceipt(
        string $tenant, string $order, string $site, string $viewer, array $head,
    ): array {
        $receipt = ($this->currentAcceptance)($tenant, $order, $site, $viewer);
        $keys = [...array_keys($head), 'buyerRef', 'acceptanceRef',
            'presentationRef', 'explicitClick', 'revoked'];
        if (!self::keys($receipt, $keys)) throw new RuntimeException();
        foreach ($head as $key => $value) {
            if (!array_key_exists($key, $receipt) || $receipt[$key] !== $value) {
                throw new RuntimeException();
            }
        }
        if ($receipt['buyerRef'] !== $viewer || !self::ref($receipt['acceptanceRef']) ||
            !self::ref($receipt['presentationRef']) ||
            $receipt['explicitClick'] !== true || $receipt['revoked'] !== false) {
            throw new RuntimeException();
        }
        return $receipt;
    }

    /**
     * A receipt's presentationRef alone is not proof that the browser saw the
     * approved HTML. Require an independent presentation observation for the
     * exact release/epoch/digest, and observe it again after rendering.
     * This read-only gate still cannot authenticate a browser or lock a release.
     * The source of servedHtmlBytes must be independent, authenticated and
     * bounded; an in-memory test fixture is NOT an operational observation.
     */
    private function readPresentation(
        string $tenant, string $order, string $site, string $viewer,
        array $head, array $receipt,
    ): array {
        $record = ($this->currentPresentation)(
            $tenant, $order, $site, $viewer, $receipt['presentationRef'],
        );
        $keys = ['tenantRef','orderRef','siteRef','buyerRef','presentationRef',
            'releaseRef','artifactEpoch','htmlSha256','briefRevision',
            'sourceVersion','observed','servedHtmlBytes'];
        if (!self::keys($record, $keys) ||
            $record['tenantRef'] !== $tenant || $record['orderRef'] !== $order ||
            $record['siteRef'] !== $site || $record['buyerRef'] !== $viewer ||
            $record['presentationRef'] !== $receipt['presentationRef'] ||
            $record['releaseRef'] !== $head['releaseRef'] ||
            $record['artifactEpoch'] !== $head['artifactEpoch'] ||
            $record['htmlSha256'] !== $head['htmlSha256'] ||
            $record['briefRevision'] !== $head['briefRevision'] ||
            $record['sourceVersion'] !== $head['sourceVersion'] ||
            $record['observed'] !== true ||
            !is_string($record['servedHtmlBytes']) ||
            strlen($record['servedHtmlBytes']) === 0 ||
            strlen($record['servedHtmlBytes']) > 262144 ||
            !hash_equals($head['htmlSha256'], hash('sha256', $record['servedHtmlBytes']))) {
            throw new RuntimeException();
        }
        return $record;
    }

    private static function keys(mixed $data, array $keys): bool
    {
        return is_array($data) && count($data) === count($keys) &&
            array_diff($keys, array_keys($data)) === [];
    }

    private static function ref(mixed $data): bool
    {
        return is_string($data) &&
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $data) === 1;
    }

    private static function digest(mixed $data): bool
    {
        return is_string($data) && preg_match('/\A[0-9a-f]{64}\z/D', $data) === 1;
    }
}
