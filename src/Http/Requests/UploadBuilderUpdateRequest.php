<?php

namespace ESolution\DataSources\Http\Requests;

use ESolution\DataSources\Models\UploadConfig;
use ESolution\DataSources\Support\DatabaseConnection;

class UploadBuilderUpdateRequest
{
    public function __construct(
        protected UploadConfig $uploadConfig
    ) {
    }

    public function rules(): array
    {
        return [
            'code' => [
                'sometimes',
                'string',
                'max:150',
                'unique:' . DatabaseConnection::validationTable('upload_configs') . ',code,' . $this->uploadConfig->id,
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'endpoint' => [
                'sometimes',
                'string',
                'max:255',
                'unique:' . DatabaseConnection::validationTable('upload_configs') . ',endpoint,' . $this->uploadConfig->id,
            ],
            'upload_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'max_file_size' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:102400'],
            'allowed_extensions' => ['sometimes', 'nullable', 'array'],
            'allowed_extensions.*' => ['nullable', 'string'],
            'multiple' => ['sometimes', 'boolean'],
            'middlewares' => ['sometimes', 'nullable', 'array'],
            'middlewares.*' => ['nullable', 'string'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
