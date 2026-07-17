<?php

namespace ESolution\DataSources\Contracts;

use ESolution\DataSources\Models\DataSource;
use Illuminate\Http\Request;

interface AfterExecuteHookInterface
{
    public function handle(
        Request $request,
        DataSource $dataSource,
        mixed $data
    ): mixed;
}
