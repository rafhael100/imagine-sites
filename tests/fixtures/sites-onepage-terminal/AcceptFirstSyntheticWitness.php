<?php
declare(strict_types=1);

namespace OnepageSitesTerminalDraft;

use RuntimeException;

/** Fixed lab facts, not discoverable from or writable by a submitted Intent. */
final readonly class AcceptFirstSyntheticWitness
{
    private const SESSION = [
        'ref'=>'fixture-session-af-a', 'buyer'=>'fixture-buyer-af-a', 'epoch'=>1,
        'mfa'=>true, 'membership'=>true, 'expires'=>7,
    ];
    private const PRESENTATION = [
        'ref'=>'fixture-presentation-af-a', 'session'=>'fixture-session-af-a',
        'bound'=>2, 'expires'=>6, 'purpose'=>'onepage-final-html',
    ];
    private const ACTION = [
        'ref'=>'fixture-action-af-a', 'presentation'=>'fixture-presentation-af-a',
        'buyer'=>'fixture-buyer-af-a', 'name'=>'accept_exact_version', 'occurred'=>3,
    ];
    private const RESERVATION = [
        'operation'=>'terminal-fixture-accept-first-a', 'action'=>'fixture-action-af-a',
        'prepared'=>4, 'commitTick'=>5, 'expires'=>6,
    ];

    /**
     * Ownership of native order and active membership are fixture assertions (not #954 native auth).
     * Inputs must come from trusted #603 bootstrap artifacts, not buyer request strings.
     * No session key: binding is a digest of a fixed synthetic session label.
     */
    public static function fromFixture(
        string $org,string $site,string $snapshot,string $receiptDigest,
        string $manifestDigest,int $sourceVersion,string $artifactDigest,int $binding,int $editorial
    ): Intent {
        $session=self::SESSION; $presentation=self::PRESENTATION;
        $action=self::ACTION; $reserve=self::RESERVATION;
        if ($session['mfa']!==true || $session['membership']!==true ||
            $session['ref']!==$presentation['session'] || $session['buyer']!==$action['buyer'] ||
            $presentation['ref']!==$action['presentation'] || $action['ref']!==$reserve['action'] ||
            $presentation['purpose']!=='onepage-final-html' || $action['name']!=='accept_exact_version' ||
            !($presentation['bound']<$action['occurred'] && $action['occurred']<$reserve['prepared'] &&
            $reserve['prepared']<$reserve['commitTick'] &&
            $reserve['commitTick']<min($session['expires'],$presentation['expires'],$reserve['expires'])) ||
            $binding!==1 || $editorial!==3) {
            throw new RuntimeException('ACCEPT_FIRST_WITNESS_REFUSED');
        }
        return new Intent($reserve['operation'],[
            'tenantRef'=>$org,'buyerRef'=>$session['buyer'],'orderRef'=>'terminal-fixture-order',
            'siteRef'=>$site,'contractRef'=>'terminal-fixture-contract','snapshotRef'=>$snapshot,
            'releaseRef'=>'terminal-fixture-release-a','presentationRef'=>$presentation['ref'],
            'actionRef'=>$action['ref'],'commandDigest'=>$receiptDigest,'manifestSha256'=>$manifestDigest,
            'htmlSha256'=>$artifactDigest,'sessionBinding'=>hash('sha256',$session['ref']),
            'contractRevision'=>1,'sourceVersion'=>$sourceVersion,'briefRevision'=>1,'approvalGeneration'=>1,
            'editorialRevision'=>$editorial,'bindingRevision'=>$binding,'sessionEpoch'=>$session['epoch'],
        ]);
    }
}
