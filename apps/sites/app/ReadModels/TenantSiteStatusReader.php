<?php

declare(strict_types=1);

namespace App\ReadModels;

use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Production-shaped, non-HTTP read model for a future customer Portal panel.
 *
 * Authorization comes exclusively from the authenticated PostgreSQL login
 * and a DBA-managed, explicitly activated login->tenant binding. No tenant,
 * contract, user or customer hint is accepted from callers.
 *
 * Never translates this status into an order, payment, client acceptance or
 * permission to publish. No public route exists.
 */
final readonly class TenantSiteStatusReader
{
    public function __construct(private Connection $database) {}

    /**
     * @return list<array{
     *   siteId:string,productCode:string,state:string,reviewRevision:?int,
     *   reviewState:?string,publishable:false
     * }>
     */
    public function list(): array
    {
        if ($this->database->getDriverName() !== 'pgsql') {
            throw new DomainException('Sites status unavailable.');
        }
        try {
            // A restricted DB login has EXECUTE ONLY; it has no SELECT on
            // sites_core tables and cannot claim an arbitrary tenantRef.
            $records=$this->database->select(
                'SELECT * FROM sites_core.read_my_site_projects()'
            );
        } catch (QueryException) {
            // Do not leak schema, tenant, login, SQL, hostname or secrets
            // through a future API error.
            throw new DomainException('Sites status unavailable.');
        }

        $result=[];
        foreach ($records as $row) {
            if (!is_object($row) ||
                !is_string($row->site_id ?? null) ||
                !preg_match(
                    '/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D',
                    $row->site_id
                ) ||
                !in_array(
                    $row->product_code ?? null,
                    ['onepage','landing_page'],
                    true
                ) ||
                ($row->lifecycle_state ?? null)!=='awaiting_official_authority' ||
                !in_array(
                    $row->publishable ?? null,
                    [false,'f','0',0],
                    true
                )) {
                throw new DomainException('Sites status failed validation.');
            }
            $reviewRevision=$row->latest_editorial_revision ?? null;
            if ($reviewRevision!==null &&
                (!is_numeric($reviewRevision) ||
                 (int)$reviewRevision<1 ||
                 (string)(int)$reviewRevision!==(string)$reviewRevision)) {
                throw new DomainException('Sites review state unavailable.');
            }
            $reviewState=$row->latest_review_state ?? null;
            if ($reviewState!==null &&
                !in_array($reviewState,[
                    'draft','internal_review','awaiting_portal_acceptance',
                ],true)) {
                throw new DomainException('Sites review state unavailable.');
            }
            $result[]=[
                'siteId'=>$row->site_id,
                'productCode'=>$row->product_code,
                'state'=>$row->lifecycle_state,
                'reviewRevision'=>$reviewRevision===null
                    ?null:(int)$reviewRevision,
                'reviewState'=>$reviewState,
                'publishable'=>false,
            ];
        }

        return $result;
    }
}
