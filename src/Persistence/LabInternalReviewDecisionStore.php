<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;
use Imagine\Sites\Production\SyntheticInternalReviewDecision;
use PDO;
use Throwable;

/**
 * F1-79: append a SINGLE canonical synthetic reviewer decision in a PG18 lab.
 * Database constraints own tenant isolation, uniqueness and append-only rights.
 * No real identity, production credential, service API or delivery capability.
 */
final class LabInternalReviewDecisionStore
{
    public static function append(PDO $db,SyntheticInternalReviewDecision $decision):void
    {
        if (PHP_SAPI!=='cli' || getenv('SITES_F79_PG_LAB')!=='1' ||
            $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
            throw new DomainException('F1-79 disabled outside PostgreSQL CLI laboratory');
        }
        try {
            $context=$db->query(
                'SELECT current_database() AS lab_db,current_user AS lab_role'
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            throw new DomainException('F1-79 database identity unavailable',previous:$e);
        }
        $role=$context['lab_role']??null;
        if (($context['lab_db']??null)!=='sites_f79_lab' ||
            !in_array($role,['sites_f79_writer_a','sites_f79_writer_b'],true)) {
            throw new DomainException('F1-79 only accepts restricted fictional tenant role');
        }
        $r=$decision->record;
        $expected=[
            'schemaVersion','tenantRef','siteRef','workflowRef','releaseRef',
            'reviewedWorkflowRevision','reviewedArtifactDigest','reviewedBundleDigest',
            'reviewedDossierDigest','decisionRef','syntheticReviewerRef','action',
            'reworkReason','acknowledgedEditorialKeys','nextWorkflowRevision',
            'nextWorkflowState','portalAuthorityRequired','syntheticOnly',
            'officialClientAcceptance','publishable',
        ];
        if (array_keys($r)!==$expected ||
            $r['schemaVersion']!=='sites.synthetic.internal-review-decision.v1' ||
            $r['portalAuthorityRequired']!==true ||
            $r['syntheticOnly']!==true ||
            $r['officialClientAcceptance']!==false ||
            $r['publishable']!==false ||
            $r['tenantRef']!==($role==='sites_f79_writer_a'?'tenant-f79-a':'tenant-f79-b') ||
            $r['reviewedWorkflowRevision']+1!==$r['nextWorkflowRevision'] ||
            $r['nextWorkflowState']!==$decision->nextWorkflow->state ||
            $r['tenantRef']!==$decision->nextWorkflow->tenantRef ||
            $r['siteRef']!==$decision->nextWorkflow->siteRef ||
            $r['workflowRef']!==$decision->nextWorkflow->workflowRef) {
            throw new DomainException('F1-79 exact decision record/role mismatch');
        }
        try {
            $json=json_encode($r,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (!hash_equals(hash('sha256',$json),$decision->reviewDigest)) {
                throw new DomainException('F1-79 evidence digest not canonical');
            }
            $stmt=$db->prepare(
                'INSERT INTO review_f79.internal_decisions('.
                'tenant_ref,site_ref,workflow_ref,release_ref,reviewed_workflow_revision,'.
                'artifact_digest,bundle_digest,dossier_digest,decision_ref,decision_action,'.
                'decision_record_digest) VALUES ('.
                ':tenant,:site,:workflow,:release,:revision,'.
                ':artifact,:bundle,:dossier,:decision,:action,:evidence)'
            );
            $stmt->execute([
                ':tenant'=>$r['tenantRef'],
                ':site'=>$r['siteRef'],
                ':workflow'=>$r['workflowRef'],
                ':release'=>$r['releaseRef'],
                ':revision'=>$r['reviewedWorkflowRevision'],
                ':artifact'=>$r['reviewedArtifactDigest'],
                ':bundle'=>$r['reviewedBundleDigest'],
                ':dossier'=>$r['reviewedDossierDigest'],
                ':decision'=>$r['decisionRef'],
                ':action'=>$r['action'],
                ':evidence'=>$decision->reviewDigest,
            ]);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Never treat database errors as a successful append; fail closed.
            throw new DomainException('F1-79 append denied by PostgreSQL',previous:$e);
        }
    }
}
