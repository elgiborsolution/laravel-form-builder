<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(DatabaseConnection::name())->table('api_configs', function (Blueprint $table): void {
            $table->index(['enabled', 'method', 'endpoint'], 'api_configs_enabled_method_endpoint_idx');
            $table->index('route_name', 'api_configs_route_name_idx');
        });
    }

    public function down(): void
    {
        Schema::connection(DatabaseConnection::name())->table('api_configs', function (Blueprint $table): void {
            $table->dropIndex('api_configs_enabled_method_endpoint_idx');
            $table->dropIndex('api_configs_route_name_idx');
        });
    }
};
