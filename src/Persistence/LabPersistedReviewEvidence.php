<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;
use Imagine\Sites\Production\SyntheticInternalReviewDecision;
use PDO;
use Throwable;

/**
 * F1-80: verify an EXACT persisted synthetic decision under a restricted
 * tenant role before using it as internal laboratory evidence.
 *
 * A matching row is not a signature, user identity, current Portal authority,
 * globally monotonic restore fence, customer approval, or publish permission.
 */
final class LabPersistedReviewEvidence
{
    /**
     * @return array<string,string|bool|int> No personal data or release capability.
     */
    public static function verify(PDO $db,SyntheticInternalReviewDecision $decision):array
    {
        if (PHP_SAPI!=='cli' || getenv('SITES_F79_PG_LAB')!=='1' ||
            $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
            throw new DomainException('persisted_review_proof_requires_pg18_lab');
        }

        $r=$decision->record;
        if (($r['schemaVersion']??null)!=='sites.synthetic.internal-review-decision.v1' ||
            ($r['syntheticOnly']??null)!==true ||
            ($r['portalAuthorityRequired']??null)!==true ||
            ($r['publishable']??null)!==false ||
            ($r['officialClientAcceptance']??null)!==false ||
            ($r['tenantRef']??null)!==$decision->nextWorkflow->tenantRef ||
            ($r['workflowRef']??null)!==$decision->nextWorkflow->workflowRef ||
            ($r['siteRef']??null)!==$decision->nextWorkflow->siteRef ||
            ($r['nextWorkflowState']??null)!==$decision->nextWorkflow->state ||
            !is_int($r['reviewedWorkflowRevision']??null) ||
            ($r['reviewedWorkflowRevision']+1)!==$decision->nextWorkflow->revision) {
            throw new DomainException('persisted_review_invalid_decision_snapshot');
        }
        try {
            $canonical=json_encode($r,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!hash_equals(hash('sha256',$canonical),$decision->reviewDigest)) {
                throw new DomainException('persisted_review_digest_mismatch');
            }
        } catch (\JsonException $e) {
            throw new DomainException('persisted_review_unserializable',previous:$e);
        }
        try {
            $context=$db->query('SELECT current_database() AS db,current_user AS actor')
                ->fetch(PDO::FETCH_ASSOC);
            $actor=$context['actor']??null;
            if (($context['db']??null)!=='sites_f79_lab' ||
                !in_array($actor,['sites_f79_writer_a','sites_f79_writer_b'],true) ||
                $r['tenantRef']!==($actor==='sites_f79_writer_a'?'tenant-f79-a':'tenant-f79-b')) {
                throw new DomainException('persisted_review_untrusted_tenant_role');
            }
            $statement=$db->prepare(
                'SELECT tenant_ref,site_ref,workflow_ref,release_ref,'.
                'reviewed_workflow_revision,artifact_digest,bundle_digest,dossier_digest,'.
                'decision_ref,decision_action,decision_record_digest,synthetic_only,'.
                'portal_authority_required,official_client_acceptance,publishable '.
                'FROM review_f79.internal_decisions '.
                'WHERE tenant_ref=:tenant AND decision_ref=:decision LIMIT 2'
            );
            $statement->execute([':tenant'=>$r['tenantRef'],':decision'=>$r['decisionRef']]);
            $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)!==1) {
                throw new DomainException('persisted_review_missing_or_ambiguous');
            }
            $row=$rows[0];
            $map=[
                'tenant_ref'=>'tenantRef',
                'site_ref'=>'siteRef',
                'workflow_ref'=>'workflowRef',
                'release_ref'=>'releaseRef',
                'reviewed_workflow_revision'=>'reviewedWorkflowRevision',
                'artifact_digest'=>'reviewedArtifactDigest',
                'bundle_digest'=>'reviewedBundleDigest',
                'dossier_digest'=>'reviewedDossierDigest',
                'decision_ref'=>'decisionRef',
                'decision_action'=>'action',
            ];
            foreach($map as $column=>$field) {
                if (!is_scalar($row[$column]??null) ||
                    !is_scalar($r[$field]??null) ||
                    !hash_equals((string)$r[$field],(string)$row[$column])) {
                    throw new DomainException('persisted_review_row_drift_'.$column);
                }
            }
            if (!is_string($row['decision_record_digest']??null) ||
                !hash_equals($decision->reviewDigest,$row['decision_record_digest'])) {
                throw new DomainException('persisted_review_record_digest_drift');
            }
            if (!self::pgTrue($row['synthetic_only']??null) ||
                !self::pgTrue($row['portal_authority_required']??null) ||
                !self::pgFalse($row['official_client_acceptance']??null) ||
                !self::pgFalse($row['publishable']??null)) {
                throw new DomainException('persisted_review_forbidden_release_flags');
            }
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DomainException('persisted_review_database_read_denied',previous:$e);
        }

        return [
            'schemaVersion'=>'sites.synthetic.persisted-review-proof.v1',
            'tenantRef'=>$r['tenantRef'],
            'siteRef'=>$r['siteRef'],
            'workflowRef'=>$r['workflowRef'],
            'releaseRef'=>$r['releaseRef'],
            'reviewedWorkflowRevision'=>$r['reviewedWorkflowRevision'],
            'decisionRef'=>$r['decisionRef'],
            'action'=>$r['action'],
            'artifactDigest'=>$r['reviewedArtifactDigest'],
            'bundleDigest'=>$r['reviewedBundleDigest'],
            'dossierDigest'=>$r['reviewedDossierDigest'],
            'decisionRecordDigest'=>$decision->reviewDigest,
            'persistedInDisposableLab'=>true,
            'portalAuthorityRequired'=>true,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
    }

    private static function pgTrue(mixed $value):bool
    {
        return $value===true || $value==='t' || $value==='1';
    }

    private static function pgFalse(mixed $value):bool
    {
        return $value===false || $value==='f' || $value==='0';
    }
}
