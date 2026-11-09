<?php

declare(strict_types=1);

require __DIR__.'/../src/Compatibility/LegacyPortalBriefingGuard.php';
require __DIR__.'/../src/Compatibility/ExactReleaseDecisionGuard.php';

use Imagine\Sites\Compatibility\LegacyPortalBriefingGuard;
use Imagine\Sites\Compatibility\ExactReleaseDecisionGuard;

$checks = 0;
function verifyF20(bool $value,string $reason): void {
    global $checks;
    if (!$value) throw new RuntimeException('FAIL '.$reason);
    ++$checks;
    echo 'PASS '.$reason."\n";
}
$brief = new LegacyPortalBriefingGuard();
$data=[
    'company_name'=>'Empresa Fictícia',
    'services'=>'Serviços fictícios para teste de briefing',
    'company_intro'=>'Apresentação demonstrativa',
    'email'=>'teste@example.invalid',
    'phone'=>'',
    'whatsapp'=>'',
    'domain'=>'example.invalid',
    'logo_unavailable'=>true,
    'manual_unavailable'=>true,
];
$args=[$data,[],'draft','client',true,true,1,1,true];
verifyF20($brief->submissionBlockers(...$args)===[], 'valid fictional draft submission accepted by parity lab');
$noConfirm=$args;$noConfirm[8]=false;
verifyF20(in_array('materials_confirmation_missing',$brief->submissionBlockers(...$noConfirm),true),
    'materials rights must be expressly confirmed');
$stale=$args;$stale[6]=0;
verifyF20(in_array('stale_briefing_version',$brief->submissionBlockers(...$stale),true),
    'stale browser draft blocked');
$crossTenant=$args;$crossTenant[4]=false;
verifyF20(in_array('only_order_owner_submits',$brief->submissionBlockers(...$crossTenant),true),
    'another order owner cannot submit');
$unpaid=$args;$unpaid[5]=false;
verifyF20(in_array('official_payment_not_confirmed',$brief->submissionBlockers(...$unpaid),true),
    'missing official commercial eligibility blocks submit');
$submitted=$args;$submitted[2]='submitted';
verifyF20(in_array('briefing_not_editable',$brief->submissionBlockers(...$submitted),true),
    'already submitted briefing is read-only for client');
$withoutLogo=$args;$withoutLogo[0]['logo_unavailable']=false;
verifyF20(in_array('logo_or_declared_unavailable_required',$brief->submissionBlockers(...$withoutLogo),true),
    'no logo and no explicit unavailable declaration blocked');
$withLogo=$withoutLogo;$withLogo[1]=['logo'];
verifyF20(!in_array('logo_or_declared_unavailable_required',$brief->submissionBlockers(...$withLogo),true),
    'uploaded fictional logo satisfies material checklist');
$withoutIntro=$args;$withoutIntro[0]['company_intro']='';$withoutIntro[0]['ai_support']=false;
verifyF20(in_array('intro_or_ai_support_required',$brief->submissionBlockers(...$withoutIntro),true),
    'briefing needs intro or explicit IA-support choice');
$withoutIntro[0]['ai_support']=true;
verifyF20(!in_array('intro_or_ai_support_required',$brief->submissionBlockers(...$withoutIntro),true),
    'explicit IA-support choice satisfies legacy material parity');
$withoutContact=$args;
$withoutContact[0]['email']='';
verifyF20(in_array('contact_required',$brief->submissionBlockers(...$withoutContact),true),
    'at least one contact required');
$oversized=$args;$oversized[0]['company_name']=str_repeat('A',161);
verifyF20(in_array('invalid_field_company_name',$brief->submissionBlockers(...$oversized),true),
    'oversized company field detected');

verifyF20($brief->reviewBlockers('submitted','approved','admin',true,true,'',2,2)===[],
    'legacy administrator review can approve eligible fictional briefing');
verifyF20(in_array('imagine_admin_only',
    $brief->reviewBlockers('submitted','approved','client',true,true,'',2,2),true),
    'client cannot approve briefing as Imagine staff');
verifyF20(in_array('revision_note_required',
    $brief->reviewBlockers('submitted','revision_requested','admin',true,true,'No',2,2),true),
    'revision request requires actionable explanatory note');
verifyF20(in_array('legacy_domain_required_before_approval',
    $brief->reviewBlockers('submitted','approved','admin',true,false,'',2,2),true),
    'legacy domain approval dependency explicitly captured but not adopted');
verifyF20(in_array('briefing_not_submitted',
    $brief->reviewBlockers('draft','approved','admin',true,true,'',2,2),true),
    'unsubmitted briefing cannot be approved');

$release = new ExactReleaseDecisionGuard();
$hash=str_repeat('a',64);
$decisionArgs=['review','client',true,true,8,8,'release-fake-002','release-fake-002',$hash,$hash,true,'approve',''];
verifyF20($release->decisionBlockers(...$decisionArgs)===[],
    'owner explicitly approves exact same fictional release and digest');
$anotherDigest=$decisionArgs;$anotherDigest[9]=str_repeat('b',64);
verifyF20(in_array('approved_bytes_digest_mismatch',$release->decisionBlockers(...$anotherDigest),true),
    'same revision label with changed HTML bytes is denied');
$anotherRelease=$decisionArgs;$anotherRelease[6]='release-fake-003';
verifyF20(in_array('release_reference_mismatch',$release->decisionBlockers(...$anotherRelease),true),
    'approval cannot silently transfer to release N+1');
$approvalMissing=$decisionArgs;$approvalMissing[10]=false;
verifyF20(in_array('express_confirmation_missing',$release->decisionBlockers(...$approvalMissing),true),
    'approval by silence forbidden');
$adminDecision=$decisionArgs;$adminDecision[1]='admin';
verifyF20(in_array('official_owner_authority_missing',$release->decisionBlockers(...$adminDecision),true),
    'Imagine administrator cannot forge client acceptance');
$changes=$decisionArgs;$changes[11]='request-changes';$changes[12]='short';
verifyF20(in_array('changes_note_required',$release->decisionBlockers(...$changes),true),
    'client changes demand a meaningful note');
verifyF20($release->publicationBlockers('approved','release-fake-002','release-fake-002',$hash,$hash,true,true)===[],
    'technical proof prerequisites satisfied in lab only');
verifyF20(in_array('origin_proof_absent',
    $release->publicationBlockers('approved','release-fake-002','release-fake-002',$hash,$hash,true,false),true),
    'legacy URL-only publish is not accepted without observed bytes');
verifyF20(in_array('authoritative_portal_denied',
    $release->publicationBlockers('approved','release-fake-002','release-fake-002',$hash,$hash,false,true),true),
    'revoked commercial authority blocks publication eligibility');
echo "\n$checks F1-20 prototype-parity and hardened exact-version tests passed. No official policy migration.\n";
