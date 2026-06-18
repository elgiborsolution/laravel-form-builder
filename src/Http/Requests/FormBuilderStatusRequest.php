<?php
namespace ESolution\DataSources\Http\Requests;

class FormBuilderStatusRequest
{
    /**
     * Validation rules used by the status endpoint.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}

