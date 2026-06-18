<?php
namespace ESolution\DataSources\Http\Requests;

use ESolution\DataSources\Models\FormBuilder;
use ESolution\DataSources\Support\DatabaseConnection;

class FormBuilderUpdateRequest
{
    public function __construct(
        protected FormBuilder $formBuilder
    ) {
    }

    /**
     * Validation rules used by the update endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'sometimes',
                'string',
                'max:150',
                'unique:' . DatabaseConnection::validationTable('form_builders') . ',code,' . $this->formBuilder->id,
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'schema' => ['sometimes', 'array'],
            'schema.title' => ['sometimes', 'string'],
            'schema.layout' => ['sometimes', 'array'],
            'schema.fields' => ['sometimes', 'array'],
        ];
    }
}
