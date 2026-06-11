<?php

namespace ESolution\DataSources\Contracts;

use ESolution\DataSources\Models\ApiConfig;
use Illuminate\Http\Request;

interface BeforeExecuteHookInterface
{
    public function handle(
        array &$payload,
        ApiConfig $apiConfig,
        Request $request
    ): void;
}
