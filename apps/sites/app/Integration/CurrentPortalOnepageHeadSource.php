<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * Future INDEPENDENT Portal authority reader for the current Onepage brief head.
 *
 * The implementation must authenticate the acting principal, verify tenant,
 * order, contract, approval/revocation and most recent revision in one trusted
 * Portal transaction. Caller-provided JSON or a Sites snapshot is NOT a source.
 * No operational adapter is registered.
 */
interface CurrentPortalOnepageHeadSource
{
    /**
     * Exact eleven-field current head or null on denial/revocation.
     * Never return original customer content or approval credentials.
     *
     * @return array<string, int|string>|null
     */
    public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?array;
}
