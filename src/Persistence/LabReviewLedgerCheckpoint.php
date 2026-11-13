<?php

declare(strict_types=1);

namespace Imagine\Sites\Persistence;

use DomainException;
use JsonException;
use PDO;
use Throwable;

/**
 * F1-82: SHA-256 checkpoint of one tenant's append-only review prefix in a
 * disposable PG18 database. Caller MUST retain the checkpoint outside the DB.
 *
 * This is not a cryptographic signature or a protected external anchor:
 * an attacker controlling both DB and checkpoint can rewrite both. A valid
 * prior prefix proves no current Portal authority or customer approval.
 */
final class LabReviewLedgerCheckpoint
{
    /** @return array<string,string|int|bool> */
    public static function capture(PDO $db,string $tenantRef,?int $throughId=null):array
    {
        if (PHP_SAPI!=='cli' || getenv('SITES_F79_PG_LAB')!=='1' ||
            $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
            ($throughId!==null && $throughId<1)) {
            throw new DomainException('ledger_checkpoint_disabled_or_invalid');
        }
        try {
            $c=$db->query('SELECT current_database() AS db,current_user AS actor')
                ->fetch(PDO::FETCH_ASSOC);
            $actor=$c['actor']??null;
            if (($c['db']??null)!=='sites_f79_lab' ||
                !in_array($actor,['sites_f79_writer_a','sites_f79_writer_b'],true) ||
                $tenantRef!==($actor==='sites_f79_writer_a'?'tenant-f79-a':'tenant-f79-b')) {
                throw new DomainException('ledger_checkpoint_wrong_database_or_tenant');
            }

            $sql='SELECT id,tenant_ref,site_ref,workflow_ref,release_ref,'.
                'reviewed_workflow_revision,artifact_digest,bundle_digest,dossier_digest,'.
                'decision_ref,decision_action,decision_record_digest,synthetic_only,'.
                'portal_authority_required,official_client_acceptance,publishable '.
                'FROM review_f79.internal_decisions WHERE tenant_ref=:tenant';
            if($throughId!==null)$sql.=' AND id<=:last_id';
            $sql.=' ORDER BY id ASC';
            $stmt=$db->prepare($sql);
            $stmt->bindValue(':tenant',$tenantRef,PDO::PARAM_STR);
            if($throughId!==null)$stmt->bindValue(':last_id',$throughId,PDO::PARAM_INT);
            $stmt->execute();
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows===[]) {
                throw new DomainException('ledger_checkpoint_cannot_anchor_empty_history');
            }
            $normalized=[];
            foreach($rows as $row) {
                if (!self::pgTrue($row['synthetic_only']??null) ||
                    !self::pgTrue($row['portal_authority_required']??null) ||
                    !self::pgFalse($row['official_client_acceptance']??null) ||
                    !self::pgFalse($row['publishable']??null)) {
                    throw new DomainException('ledger_checkpoint_contains_forbidden_flags');
                }
                $normalized[]=[
                    'id'=>(int)$row['id'],
                    'tenantRef'=>$row['tenant_ref'],
                    'siteRef'=>$row['site_ref'],
                    'workflowRef'=>$row['workflow_ref'],
                    'releaseRef'=>$row['release_ref'],
                    'workflowRevision'=>(int)$row['reviewed_workflow_revision'],
                    'artifactDigest'=>$row['artifact_digest'],
                    'bundleDigest'=>$row['bundle_digest'],
                    'dossierDigest'=>$row['dossier_digest'],
                    'decisionRef'=>$row['decision_ref'],
                    'action'=>$row['decision_action'],
                    'decisionRecordDigest'=>$row['decision_record_digest'],
                ];
            }
            $payload=json_encode($normalized,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            return [
                'schemaVersion'=>'sites.synthetic.pg-review-checkpoint.v1',
                'tenantRef'=>$tenantRef,
                'throughId'=>end($normalized)['id'],
                'rowCount'=>count($normalized),
                'prefixSha256'=>hash('sha256',$payload),
                'externalAnchorRequired'=>true,
                'syntheticOnly'=>true,
                'publishable'=>false,
            ];
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DomainException('ledger_checkpoint_database_unavailable',previous:$e);
        }
    }

    /**
     * Check exact anchored history (throughId), allowing later appends.
     * A missing/changed anchored row is DENY. Never trusts DB alone.
     *
     * @param array<string,mixed> $anchor A checkpoint retained by caller
     */
    public static function verify(PDO $db,array $anchor):bool
    {
        $keys=['schemaVersion','tenantRef','throughId','rowCount','prefixSha256',
            'externalAnchorRequired','syntheticOnly','publishable'];
        if (array_keys($anchor)!==$keys ||
            $anchor['schemaVersion']!=='sites.synthetic.pg-review-checkpoint.v1' ||
            !is_string($anchor['tenantRef']) ||
            !is_int($anchor['throughId']) || $anchor['throughId']<1 ||
            !is_int($anchor['rowCount']) || $anchor['rowCount']<1 ||
            !is_string($anchor['prefixSha256']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$anchor['prefixSha256']) ||
            $anchor['externalAnchorRequired']!==true ||
            $anchor['syntheticOnly']!==true || $anchor['publishable']!==false) {
            return false;
        }
        try {
            $actual=self::capture($db,$anchor['tenantRef'],$anchor['throughId']);
            return $actual===$anchor;
        } catch(Throwable) {
            return false;
        }
    }

    private static function pgTrue(mixed $v):bool
    {
        return $v===true || $v==='t' || $v==='1';
    }
    private static function pgFalse(mixed $v):bool
    {
        return $v===false || $v==='f' || $v==='0';
    }
}
