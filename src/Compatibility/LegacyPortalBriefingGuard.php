<?php

declare(strict_types=1);

namespace Imagine\Sites\Compatibility;

use InvalidArgumentException;

/**
 * Non-operational compatibility specification derived from the old
 * Portal Node/SQLite prototype, NOT authoritative permissions.
 *
 * Portal remains source of truth for payment, owner and approval. All
 * booleans in this lab are synthetic inputs; never trust a client payload.
 */
final class LegacyPortalBriefingGuard
{
    private const EDITABLE = ['draft', 'revision_requested'];
    private const REQUIRED_LIMITS = [
        'company_name' => 160,
        'company_intro' => 10000,
        'services' => 10000,
        'domain' => 253,
        'email' => 254,
        'phone' => 60,
        'whatsapp' => 60,
    ];

    /**
     * Return machine-readable candidate blockers. No DB, I/O, auth or PII.
     *
     * @param array<string, mixed> $data fictional briefing body
     * @param list<string> $fileKinds trusted mock uploaded material types
     * @return list<string>
     */
    public function submissionBlockers(
        array $data,
        array $fileKinds,
        string $status,
        string $role,
        bool $isOrderOwner,
        bool $officialPaymentConfirmed,
        int $expectedVersion,
        int $currentVersion,
        bool $expressMaterialsConfirmation,
    ): array {
        $errors = [];
        if (!in_array($status, self::EDITABLE, true)) $errors[] = 'briefing_not_editable';
        if ($role !== 'client' || !$isOrderOwner) $errors[] = 'only_order_owner_submits';
        if (!$officialPaymentConfirmed) $errors[] = 'official_payment_not_confirmed';
        if ($expectedVersion < 0 || $expectedVersion !== $currentVersion) $errors[] = 'stale_briefing_version';
        if (!$expressMaterialsConfirmation) $errors[] = 'materials_confirmation_missing';

        foreach (self::REQUIRED_LIMITS as $field => $maxBytes) {
            $value = $data[$field] ?? '';
            if (!is_string($value) || strlen($value) > $maxBytes || !mb_check_encoding($value, 'UTF-8')) {
                $errors[] = 'invalid_field_'.$field;
            }
        }

        $has = static fn (string $field): bool =>
            isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '';

        if (!$has('company_name')) $errors[] = 'company_name_missing';
        if (!$has('services')) $errors[] = 'services_missing';
        if (!$has('company_intro') && ($data['ai_support'] ?? null) !== true) {
            $errors[] = 'intro_or_ai_support_required';
        }
        if (!$has('email') && !$has('phone') && !$has('whatsapp')) {
            $errors[] = 'contact_required';
        }
        if (!in_array('logo', $fileKinds, true) && ($data['logo_unavailable'] ?? null) !== true) {
            $errors[] = 'logo_or_declared_unavailable_required';
        }
        if (!in_array('manual', $fileKinds, true) && ($data['manual_unavailable'] ?? null) !== true) {
            $errors[] = 'manual_or_declared_unavailable_required';
        }
        return array_values(array_unique($errors));
    }

    /**
     * Old Portal prototype admin-review behavior; 'domain_required' is a
     * legacy difference that MUST be reviewed before new product adoption.
     *
     * @return list<string>
     */
    public function reviewBlockers(
        string $status,
        string $reviewAction,
        string $actorRole,
        bool $officialPaymentConfirmed,
        bool $domainProvided,
        string $reviewNote,
        int $expectedVersion,
        int $currentVersion,
    ): array {
        $errors = [];
        if ($actorRole !== 'admin') $errors[] = 'imagine_admin_only';
        if (!$officialPaymentConfirmed) $errors[] = 'official_payment_not_confirmed';
        if ($status !== 'submitted') $errors[] = 'briefing_not_submitted';
        if (!in_array($reviewAction, ['approved', 'revision_requested'], true)) {
            $errors[] = 'unsupported_review_action';
        }
        if ($expectedVersion < 0 || $expectedVersion !== $currentVersion) {
            $errors[] = 'stale_briefing_version';
        }
        if (strlen($reviewNote) > 4000) $errors[] = 'review_note_too_long';
        if ($reviewAction === 'revision_requested' && strlen(trim($reviewNote)) < 5) {
            $errors[] = 'revision_note_required';
        }
        if ($reviewAction === 'approved' && !$domainProvided) {
            $errors[] = 'legacy_domain_required_before_approval';
        }
        return $errors;
    }
}
