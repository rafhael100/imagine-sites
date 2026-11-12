<?php

declare(strict_types=1);

namespace Imagine\Sites\Security;

use DomainException;
use Imagine\Sites\Application\LabPostgresCurrentPortalReviewSource;
use Imagine\Sites\Production\SyntheticProductionWorkflow;
use PDO;
use PDOException;
use Throwable;

/**
 * F1-61: OFFLINE-only bridge from F1-50 verified HMAC v2 to F1-60 DB lock fence.
 *
 * This does NOT authenticate services, execute over a network, allow delivery
 * or guarantee atomicity across the separate Portal/Sites databases.
 */
final class LabAtomicReviewHandoffVerifier
{
    /** @param array<string,mixed> $envelope
     *  @return array<string,string|int|bool> NON-PUBLISHABLE review proposal
     */
    public static function verify(
        array $envelope,
        SyntheticProductionWorkflow $workflow,
        string $releaseRef,
        string $syntheticKey,
        int $now,
        PDO $db,
    ):array {
        if (PHP_SAPI!=='cli' || getenv('SITES_F61_PG_LAB')!=='1' ||
            getenv('SITES_F49_PG_LAB')!=='1') {
            throw new DomainException('Atomic review verifier is disabled outside CLI lab.');
        }
        try {
            $context=$db->query(
                'SELECT current_database() AS db, current_user AS role_name'
            )->fetch(PDO::FETCH_ASSOC);
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' ||
                !is_array($context) ||
                ($context['db']??null)!=='sites_auth_replay_lab' ||
                !in_array($context['role_name']??null,
                    ['sites_review_tenant_a','sites_review_tenant_b'],true)) {
                throw new DomainException('Not a restricted PostgreSQL lab review role.');
            }
        } catch(PDOException $e) {
            throw new DomainException('PostgreSQL review lab unavailable.',previous:$e);
        }

        // Never trust the envelope to grant access. F1-50 MUST verify its
        // canonical HMAC, expiry, exact local workflow and release bytes
        // before it invokes the nonce store. The store then RECHECKS current
        // authority under a row lock, making status+nonce one DB operation.
        $candidate=$envelope['candidate']??null;
        $epoch=$envelope['authorizationRevision']??null;
        if (!is_array($candidate) || !is_int($epoch) || $epoch<1) {
            throw new DomainException('Malformed epoch-bound review proposal.');
        }

        $fence=new class($db,$candidate,$epoch) implements ReplayStore {
            /** @param array<string,mixed> $candidate */
            public function __construct(
                private readonly PDO $db,
                private readonly array $candidate,
                private readonly int $epoch,
            ) {}

            public function rememberOnce(
                string $issuer,
                string $nonce,
                int $expiresAt
            ):bool {
                if ($issuer!=='imagine-sites:review-handoff.v2')return false;
                try {
                    $query=$this->db->prepare(
                        'SELECT auth_replay.consume_current_review_v2(
                            :tenant,:site,:workflow,:revision,:release,:digest,
                            :epoch,:nonce,to_timestamp(:expires)
                        )'
                    );
                    foreach([
                        'tenant'=>$this->candidate['tenantRef']??null,
                        'site'=>$this->candidate['siteRef']??null,
                        'workflow'=>$this->candidate['workflowRef']??null,
                        'revision'=>$this->candidate['workflowRevision']??null,
                        'release'=>$this->candidate['releaseRef']??null,
                        'digest'=>$this->candidate['artifactDigest']??null,
                        'epoch'=>$this->epoch,
                        'nonce'=>$nonce,
                        'expires'=>$expiresAt,
                    ] as $field=>$value) {
                        if(!is_int($value) && !is_string($value))return false;
                        $query->bindValue(':'.$field,$value,
                            is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
                    }
                    if(!$query->execute())return false;
                    $value=$query->fetchColumn();
                    return $value===true || $value==='t' || $value==='1';
                } catch (Throwable) {
                    // A database timeout, missing schema, revocation,
                    // uniqueness conflict or broken session is a DENY.
                    return false;
                }
            }
        };

        // Independent source check remains useful; it is NOT the atomic
        // proof. The single DB operation in $fence is the final authority.
        $source=new LabPostgresCurrentPortalReviewSource($db);
        return SyntheticReviewHandoffEpochEnvelope::verify(
            $envelope,$workflow,$releaseRef,$syntheticKey,$now,$fence,$source
        );
    }
}
