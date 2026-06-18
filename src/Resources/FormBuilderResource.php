<?php
namespace ESolution\DataSources\Resources;

use ESolution\DataSources\Models\FormBuilder;

class FormBuilderResource
{
    /**
     * Transform a model into the summary payload used by the list endpoint.
     *
     * @param FormBuilder $formBuilder
     * @return array<string, mixed>
     */
    public static function summary(FormBuilder $formBuilder): array
    {
        return [
            'id' => $formBuilder->id,
            'code' => $formBuilder->code,
            'name' => $formBuilder->name,
            'enabled' => (bool) $formBuilder->enabled,
        ];
    }

    /**
     * Transform a model into the detail payload used by the ID endpoint.
     *
     * @param FormBuilder $formBuilder
     * @return array<string, mixed>
     */
    public static function detail(FormBuilder $formBuilder): array
    {
        return [
            'id' => $formBuilder->id,
            'code' => $formBuilder->code,
            'name' => $formBuilder->name,
            'schema' => $formBuilder->schema ?? (object) [],
        ];
    }

    /**
     * Return the raw schema payload used by the code endpoint.
     *
     * @param FormBuilder $formBuilder
     * @return array<string, mixed>|object
     */
    public static function schema(FormBuilder $formBuilder): array|object
    {
        return $formBuilder->schema ?? (object) [];
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
                $rows[] = self::summary($item);
            }
        }

        return $rows;
    }
}

