<?php

namespace ESolution\DataSources\Exceptions;

use InvalidArgumentException;

class InvalidRuntimeVariableException extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct('Invalid runtime variable: ' . trim($key));
    }
}
