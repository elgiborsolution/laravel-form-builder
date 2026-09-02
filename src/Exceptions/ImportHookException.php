<?php

namespace ESolution\DataSources\Exceptions;

use RuntimeException;

/** Aborts the current Import Builder request with a controlled API response. */
class ImportHookException extends RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(
        protected int $statusCode = 422,
        string $message = 'An import hook error occurred.',
        protected array $data = []
    ) {
        parent::__construct($message !== '' ? $message : 'An import hook error occurred.', $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }
}
