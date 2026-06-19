<?php
namespace ESolution\DataSources\Resources;

use ESolution\DataSources\Models\FormBuilder;

class FormBuilderResource
{
    /**
     * Transform a model into the API payload used by list and detail endpoints.
     *
     * @param FormBuilder $formBuilder
     * @return array<string, mixed>
     */
    public static function detail(FormBuilder $formBuilder): array
    {
        $payload = $formBuilder->toArray();
        $payload['schema'] = self::normalizeSchemaValue($payload['schema'] ?? $formBuilder->schema ?? []);

        return $payload;
    }

    /**
     * Return the raw schema payload used by the code endpoint.
     *
     * @param FormBuilder $formBuilder
     * @return array<string, mixed>|object
     */
    public static function schema(FormBuilder $formBuilder): array|object
    {
        return self::normalizeSchemaValue($formBuilder->schema ?? []);
    }

    /**
     * Transform a collection of models into list payload rows.
     *
     * @param iterable<int, FormBuilder> $items
     * @return array<int, array<string, mixed>>
     */
    public static function summaries(iterable $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            if ($item instanceof FormBuilder) {
                $rows[] = self::detail($item);
            }
        }

        return $rows;
    }

    /**
     * Normalize schema payloads so JSON strings are returned as arrays/objects.
     *
     * @param mixed $schema
     * @return array|object
     */
    protected static function normalizeSchemaValue(mixed $schema): array|object
    {
        if (is_array($schema) || is_object($schema)) {
            return $schema;
        }

        if (! is_string($schema)) {
            return [];
        }

        $trimmed = trim($schema);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded ?? [];
        }

        return $schema;
    }
}
