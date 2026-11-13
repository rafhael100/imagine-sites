<?php

declare(strict_types=1);

namespace Imagine\Sites\Integration;

use InvalidArgumentException;

/**
 * Pure CI-only parity proposal for Imagine Site contact form completeness.
 * No form route, HTTP, consent, storage, credentials, CRM or PII logging.
 */
final class SyntheticSiteContactMinimumInspector
{
    public function inspect(
        ?string $name,
        ?string $email,
        ?string $phone,
    ): SyntheticSiteContactMinimumAssessment {
        foreach ([$name, $email, $phone] as $value) {
            if ($value === null) continue;
            if (trim($value) === '' || strlen($value) > 1024
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvalidArgumentException('Invalid synthetic contact field.');
            }
        }

        $hasName = $name !== null && trim($name) !== '';
        $emailCandidate = $email !== null
            && $email === trim($email)
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $phoneCandidate = $this->plausiblePhone($phone);
        return new SyntheticSiteContactMinimumAssessment(
            namePresent: $hasName,
            emailCandidateValid: $emailCandidate,
            phoneCandidateValid: $phoneCandidate,
            invalidSuppliedContact: ($email !== null && !$emailCandidate)
                || ($phone !== null && !$phoneCandidate)
        );
    }

    private function plausiblePhone(?string $phone): bool
    {
        if ($phone === null
            || preg_match('/\A\+?[0-9][0-9 ()-]{0,39}\z/D', $phone) !== 1) {
            return false;
        }
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $count = strlen($digits);
        return $count >= 8 && $count <= 15;
    }
}
