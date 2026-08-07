<?php

namespace ESolution\DataSources\Contracts;

use ESolution\DataSources\Models\ImportConfig;
use Illuminate\Http\Request;

interface ImportBeforeExecuteHookInterface
{
    public function handle(
        array &$payload,
        ImportConfig $config,
        Request $request
    ): void;
}
