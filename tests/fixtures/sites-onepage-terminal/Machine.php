<?php
declare(strict_types=1);

namespace OnepageSitesTerminalDraft;

use InvalidArgumentException;
use Throwable;

enum Verdict { case Eligible; case Vetoed; case Cancelled; case Unknown; }
enum Terminal { case Accepted; case Rejected; case Cancelled; }
enum Outcome { case Pending; case Conflict; case Proposed; case DurablyRecorded; }

final readonly class Intent
{
    /** @var array<string,string|int> */
    public array $tuple;

    public function __construct(public string $operationRef, array $tuple)
    {
        $hashes=['commandDigest','manifestSha256','htmlSha256','sessionBinding'];
        $refs=['tenantRef','buyerRef','orderRef','siteRef','contractRef','snapshotRef',
            'releaseRef','presentationRef','actionRef'];
        $numbers=['contractRevision','sourceVersion','briefRevision','approvalGeneration',
            'editorialRevision','bindingRevision','sessionEpoch'];
        if (!self::ref($operationRef) || count($tuple)!==count($hashes)+count($refs)+count($numbers)) {
            throw new InvalidArgumentException();
        }
        foreach ($hashes as $key) {
            if (!is_string($tuple[$key]??null) || preg_match('/\A[a-f0-9]{64}\z/D',$tuple[$key])!==1) {
                throw new InvalidArgumentException();
            }
        }
        foreach ($refs as $key) if (!self::ref($tuple[$key]??null)) throw new InvalidArgumentException();
        foreach ($numbers as $key) {
            $v=$tuple[$key]??null;
            if (!is_int($v) || $v<($key==='sourceVersion' ? 0 : 1) ||
                ($key==='sourceVersion' && $v>9007199254740991)) throw new InvalidArgumentException();
        }
        $clean=[];
        foreach ([...$hashes,...$refs,...$numbers] as $key) $clean[$key]=$tuple[$key];
        ksort($clean,SORT_STRING);
        $this->tuple=$clean;
    }

    public function same(self $other): bool
    {
        return $this->operationRef===$other->operationRef && $this->tuple===$other->tuple;
    }

    private static function ref(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$value)===1;
    }
}

final readonly class ProposedDecision
{
    public function __construct(public Intent $intent, public Terminal $terminal) {}
}

/** Construct only on authenticated observation of a committed row, never from a proposal. */
final readonly class DurablyRecordedDecision
{
    public function __construct(public Intent $intent, public Terminal $terminal) {}
}

final readonly class Result
{
    private function __construct(public Outcome $outcome, public ?ProposedDecision $proposal, public ?DurablyRecordedDecision $record) {}

    public static function pending(): self {return new self(Outcome::Pending,null,null);}
    public static function conflict(): self {return new self(Outcome::Conflict,null,null);}
    public static function proposed(ProposedDecision $decision): self
    {return new self(Outcome::Proposed,$decision,null);}
    public static function recorded(DurablyRecordedDecision $decision): self
    {return new self(Outcome::DurablyRecorded,null,$decision);}
}

/**
 * Implementer must be the admitted authenticated operation boundary. No runtime
 * implementation exists here. An Intent never authorizes its own operation.
 */
interface DecisionAuthority
{
    /**
     * Caller holds the Sites release lock shared by revoke/bind and all mutations
     * relevant to eligibility. Eligible requires exact current nonrevoked tuple
     * and independent Portal authorization/reservation enforceable until this
     * Sites transaction commits; Vetoed and Cancelled likewise require
     * authenticated exact operation and authoritative evidence. Unknown is not denial.
     */
    public function observeUnderDecisionLock(Intent $intent): Verdict;

    /** Authorizes historical result access only; never refreshes acceptance or grants release. */
    public function mayReadTerminal(Intent $intent): bool;
}

final readonly class SitesTerminalMachine
{
    public function __construct(private ?DecisionAuthority $authority=null) {}

    /**
     * Pure proposed transition. Caller loads the authoritative record by operation
     * reference under the same lock; a ProposedDecision is NOT a receipt. Atomically
     * insert one terminal row (unique op and one accepted exact presentation/
     * tuple), or retry observation after conflict; resolve the commit before
     * exporting any decision. An unknown result cannot trigger a retry/write.
     */
    public function decide(Intent $wanted, ?DurablyRecordedDecision $existing): Result
    {
        if ($this->authority===null) return Result::pending();
        try {
            if ($existing!==null) {
                if (!$this->authority->mayReadTerminal($wanted)) return Result::pending();
                return $existing->intent->same($wanted)
                    ? Result::recorded($existing) : Result::conflict();
            }
            $terminal=match($this->authority->observeUnderDecisionLock($wanted)) {
                Verdict::Eligible=>Terminal::Accepted,
                Verdict::Vetoed=>Terminal::Rejected,
                Verdict::Cancelled=>Terminal::Cancelled,
                Verdict::Unknown=>null,
            };
            return $terminal===null ? Result::pending() : Result::proposed(new ProposedDecision($wanted,$terminal));
        } catch (Throwable) {
            return Result::pending();
        }
    }
}

/**
 * Portal only sees decisions from a future authenticated durable Sites read.
 * Do not pass uncommitted proposals here. Null/ACK-loss retains the reservation;
 * there is no "try again" action and cancellation is a Sites terminal outcome.
 */
interface DurableTerminalAuthority
{
    /** Authenticated read of committed Sites decision only; null on missing/uncertain. */
    public function readCommitted(Intent $prepared): ?DurablyRecordedDecision;
}

final readonly class PortalReconciliation
{
    public function __construct(private ?DurableTerminalAuthority $authority=null) {}

    public function observe(Intent $prepared): Result
    {
        if ($this->authority===null) return Result::pending();
        try {
            $durable=$this->authority->readCommitted($prepared);
            if ($durable===null) return Result::pending();
            return $durable->intent->same($prepared) ? Result::recorded($durable) : Result::conflict();
        } catch (Throwable) {
            return Result::pending();
        }
    }
}
