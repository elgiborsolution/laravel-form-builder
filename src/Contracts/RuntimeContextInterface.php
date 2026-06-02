<?php

namespace ESolution\DataSources\Contracts;

interface RuntimeContextInterface
{
    public function get(string $key, mixed $default = null): mixed;
}
