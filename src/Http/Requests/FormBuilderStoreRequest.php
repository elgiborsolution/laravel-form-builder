<?php
namespace ESolution\DataSources\Http\Requests;

use ESolution\DataSources\Support\DatabaseConnection;

class FormBuilderStoreRequest
{
    /**
     * Validation rules used by the create endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:150',
                'unique:' . DatabaseConnection::validationTable('form_builders') . ',code',
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
            'schema' => ['required', 'array']
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'schema.required' => 'The schema field is required.',
        ];
    }
}
