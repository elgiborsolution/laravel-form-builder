<?php

namespace ESolution\DataSources\Http\Requests;

class UploadBuilderStatusRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
