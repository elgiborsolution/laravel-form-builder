<?php

namespace ESolution\DataSources\Support;

class FilterOperatorResolver
{
    /**
     * Resolve the SQL operator for a filter type.
     *
     * @param string $type
     * @return string
     */
    public static function resolve(string $type): string
    {
        $normalized = strtolower(trim($type));

        return match ($normalized) {
            'text', 'textarea', 'email' => 'LIKE',
            'number', 'currency', 'date', 'datetime', 'select', 'radio', 'checkbox', 'switch', 'data-picker', 'dropdown' => '=',
            default => '=',
        };
    }
}
