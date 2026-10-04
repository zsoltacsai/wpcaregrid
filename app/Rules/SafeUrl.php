<?php

namespace App\Rules;

use App\Content\SafeUrl as Url;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A link target owner content may use (see App\Content\SafeUrl). */
class SafeUrl implements ValidationRule
{
    public function __construct(private readonly bool $sameHost = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Url::isSafe($value, $this->sameHost)) {
            $fail($this->sameHost ? __('owner.validation.own_url', ['host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST)]) : __('owner.validation.safe_url'));
        }
    }
}
