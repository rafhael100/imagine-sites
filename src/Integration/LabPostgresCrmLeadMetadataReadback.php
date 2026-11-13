<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use PDO;
use Throwable;

/**
 * F1-134: restricted laboratory readback of a UNIQUE metadata-only
 * physical row. The caller must be in an approved synthetic transaction.
 * No row is authoritative proof of customer consent or CRM persistence.
 */
final class LabPostgresCrmLeadMetadataReadback
{
    public function __construct(private readonly PDO $db) {}

    public function inspect(
        SyntheticCrmLeadEnvelopeV1Draft $incoming
    ):SyntheticCrmLeadMetadataReadbackAssessment {
        if(PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('GITHUB_ACTIONS')!=='true' ||
            getenv('SITES_F133_LEAD_PG_LAB')!=='1' ||
            getenv('DB_HOST')!=='127.0.0.1' ||
            getenv('DB_DATABASE')!=='sites_f133_lead_lab') {
            return new SyntheticCrmLeadMetadataReadbackAssessment('LAB_DISABLED');
        }
        if(!$this->db->inTransaction()) {
            return new SyntheticCrmLeadMetadataReadbackAssessment('TRANSACTION_REQUIRED');
        }
        try {
            $identity=$this->db->query(
                "SELECT current_user AS role,current_database() AS db,
                 current_setting('app.tenant_id',true) AS tenant"
            )->fetch(PDO::FETCH_ASSOC);
            if(!is_array($identity) ||
                $identity['role']!=='sites_f133_lead_app' ||
                $identity['db']!=='sites_f133_lead_lab' ||
                $identity['tenant']!==$incoming->organizationReference) {
                return new SyntheticCrmLeadMetadataReadbackAssessment('UNTRUSTED_LAB_CONTEXT');
            }
            $q=$this->db->prepare(
                "SELECT binding_reference::text AS binding,
                   site_reference::text AS site,
                   form_reference::text AS form,
                   destination_reference::text AS destination
                 FROM f133_lead.synthetic_metadata_claims
                 WHERE organization_reference=? AND submission_reference=?"
            );
            $q->execute([$incoming->organizationReference,$incoming->submissionReference]);
            $record=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($record)) {
                return new SyntheticCrmLeadMetadataReadbackAssessment('NO_STORED_METADATA');
            }
            if($q->fetch(PDO::FETCH_ASSOC)!==false) {
                return new SyntheticCrmLeadMetadataReadbackAssessment('INCONCLUSIVE_DUPLICATE');
            }
            if($record['binding']!==$incoming->bindingReference ||
                $record['site']!==$incoming->siteReference ||
                $record['form']!==$incoming->formReference ||
                $record['destination']!==$incoming->destinationReference) {
                return new SyntheticCrmLeadMetadataReadbackAssessment('PINNED_CONTEXT_CONFLICT');
            }
            return new SyntheticCrmLeadMetadataReadbackAssessment('METADATA_MATCH_UNVERIFIED');
        } catch(Throwable) {
            return new SyntheticCrmLeadMetadataReadbackAssessment('INCONCLUSIVE_DATABASE');
        }
    }
}
