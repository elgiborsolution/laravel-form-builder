<?php

namespace ESolution\DataSources\Support\Concerns;

use Illuminate\Support\Facades\Log;

trait NormalizesJsonPayload
{
    /**
     * Normalize a JSON-backed attribute into a native array when safe.
     *
     * Only values that look like JSON objects/arrays are decoded. If decoding
     * fails, the original value is returned unchanged so no data is lost.
     *
     * @param mixed $value
     * @param string $context
     * @return mixed
     */
    protected function normalizeJsonPayload(mixed $value, string $context = ''): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $original = $value;
        $current = trim($value);

        if ($current === '') {
            return $value;
        }

        $attempts = 0;

        while (is_string($current) && $current !== '' && $attempts < 5) {
            $firstChar = $current[0] ?? '';

            if (! $this->looksLikeJsonValue($current)) {
                break;
            }

            $decoded = json_decode($current, true);
            $jsonError = json_last_error();

            if ($context !== '') {
                Log::debug('JSON decode check', [
                    'field' => $context,
                    'original' => $original,
                    'decoded' => $decoded,
                    'json_error' => json_last_error_msg(),
                    'attempt' => $attempts + 1,
                    'first_char' => $firstChar,
                ]);
            }

            if ($jsonError !== JSON_ERROR_NONE) {
                Log::warning('JSON decode failed for builder response field', [
                    'field' => $context,
                    'original' => $original,
                    'json_error' => json_last_error_msg(),
                ]);

                return $original;
            }

            if (is_string($decoded)) {
                $decodedTrimmed = trim($decoded);

                if ($decodedTrimmed === $current) {
                    return $decoded;
                }

                $current = $decodedTrimmed;
                $attempts++;
                continue;
            }

            return $decoded;
        }

        return $original;
    }

    /**
     * Determine whether a string is likely JSON data that should be decoded.
     */
    protected function looksLikeJsonValue(string $value): bool
    {
        $trimmed = ltrim($value);

        if ($trimmed === '') {
            return false;
        }

        return in_array($trimmed[0], ['[', '{', '"', 't', 'f', 'n', '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true);
    }
}
