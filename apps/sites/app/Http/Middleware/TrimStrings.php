<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as FrameworkTrimStrings;

/** Preserve invalid briefing bytes until field validation can reject them. */
final class TrimStrings extends FrameworkTrimStrings
{
    protected function transform($key, $value)
    {
        // The framework trims NUL at the edges. Never turn a malformed brief
        // into accepted text; valid values keep the existing framework behavior.
        if (in_array($key, ['brand', 'headline', 'description', 'call_to_action', 'benefits'], true) &&
            is_string($value) && str_contains($value, "\0")) {
            return $value;
        }
        return parent::transform($key, $value);
    }
}
