<?php

namespace ESolution\DataSources\Exceptions;

use InvalidArgumentException;

/** Marks one normalized worksheet row as failed during a Before Stage hook. */
class ImportRowValidationException extends InvalidArgumentException
{
    /**
     * @param array<string, array<int, string>|string> $errors
     */
    public function __construct(
        protected int $masterIndex,
        protected string $type,
        protected ?int $childIndex,
        protected int $rowIndex,
        protected array $errors
    ) {
        if (! in_array($type, ['parent', 'child'], true)) {
            throw new InvalidArgumentException('Import row validation type must be parent or child.');
        }
        if ($type === 'child' && $childIndex === null) {
            throw new InvalidArgumentException('A child index is required for child row validation.');
        }
        if ($errors === []) {
            throw new InvalidArgumentException('Import row validation errors cannot be empty.');
        }

        parent::__construct('Import row validation failed.');
    }

    public function getMasterIndex(): int { return $this->masterIndex; }
    public function getType(): string { return $this->type; }
    public function getChildIndex(): ?int { return $this->childIndex; }
    public function getRowIndex(): int { return $this->rowIndex; }

    /** @return array<string, array<int, string>|string> */
    public function getErrors(): array { return $this->errors; }
}
