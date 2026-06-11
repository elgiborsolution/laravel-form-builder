<?php

namespace ESolution\DataSources\Exceptions;

use RuntimeException;

class ApiHookException extends RuntimeException
{
    protected int $statusCode;

    /**
     * @var array<string, mixed>
     */
    protected array $data;

    public function __construct(
        int $statusCode = 422,
        string $message = 'An error occurred.',
        array $data = []
    ) {
        parent::__construct($message !== '' ? $message : 'An error occurred.', $statusCode);

        $this->statusCode = $statusCode;
        $this->data = $data;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponsePayload(): array
    {
        $payload = [
            'message' => $this->getMessage() !== '' ? $this->getMessage() : 'An error occurred.',
        ];

        $payload['data'] = $this->data;

        return $payload;
    }
}
