<?php

namespace ESolution\DataSources\Contracts;

use ESolution\DataSources\Models\ImportConfig;
use Illuminate\Http\Request;

interface ImportAfterExecuteHookInterface
{
    public function handle(
        Request $request,
        ImportConfig $config,
        mixed $data
    ): mixed;
}
