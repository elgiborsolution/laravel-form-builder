<?php

namespace ESolution\DataSources\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class DoesNotEndWithImport implements ValidationRule
{
    public const MESSAGE = 'The endpoint cannot end with "/import" because it is reserved for Import Builder.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $path = trim(trim($value), '/');

        if ($path === '') {
            return;
        }

        $segments = explode('/', $path);
        $lastSegment = trim((string) end($segments));

        if (strcasecmp($lastSegment, 'import') === 0) {
            $fail(self::MESSAGE);
        }
    }
}
