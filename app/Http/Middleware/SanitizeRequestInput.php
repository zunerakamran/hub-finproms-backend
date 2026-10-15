<?php

namespace App\Http\Middleware;

use App\Support\InputSanitizer;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * NFR-SEC-VAL — scrub null bytes / control characters from all request strings
 * before validation and controllers run. Does not strip HTML (field-specific
 * SafeHtml / InputSanitizer::richHtml handles that after validate()).
 */
class SanitizeRequestInput extends TransformsRequest
{
    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (! is_string($value)) {
            return $value;
        }

        if (! config('security_app.sanitize_request_input', true)) {
            return $value;
        }

        return InputSanitizer::wire($value);
    }
}
