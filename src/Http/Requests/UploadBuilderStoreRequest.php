<?php

namespace ESolution\DataSources\Http\Requests;

use ESolution\DataSources\Support\DatabaseConnection;

class UploadBuilderStoreRequest
{
    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:150',
                'unique:' . DatabaseConnection::validationTable('upload_configs') . ',code',
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'endpoint' => [
                'required',
                'string',
                'max:255',
                'unique:' . DatabaseConnection::validationTable('upload_configs') . ',endpoint',
            ],
            'upload_path' => ['nullable', 'string', 'max:255'],
            'max_file_size' => ['nullable', 'integer', 'min:1', 'max:102400'],
            'allowed_extensions' => ['nullable', 'array'],
            'allowed_extensions.*' => ['nullable', 'string'],
            'multiple' => ['nullable', 'boolean'],
            'middlewares' => ['nullable', 'array'],
            'middlewares.*' => ['nullable', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }
}
