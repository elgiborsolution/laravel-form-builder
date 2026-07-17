<?php

namespace ESolution\DataSources\Contracts;

use ESolution\DataSources\Models\DataSource;
use Illuminate\Http\Request;

interface DataSourceBeforeExecuteHookInterface
{
    public function handle(
        array &$payload,
        DataSource $dataSource,
        Request $request
    ): void;
}
