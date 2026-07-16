<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if (! $schema->hasColumn('data_sources', 'database_scope')) {
            $schema->table('data_sources', function (Blueprint $table): void {
                $table->enum('database_scope', ['central', 'tenant'])
                    ->default('central')
                    ->after('table_name');
            });
        }

        if (! $schema->hasColumn('api_configs', 'database_scope')) {
            $schema->table('api_configs', function (Blueprint $table): void {
                $table->enum('database_scope', ['central', 'tenant'])
                    ->default('central')
                    ->after('method');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if ($schema->hasColumn('data_sources', 'database_scope')) {
            $schema->table('data_sources', function (Blueprint $table): void {
                $table->dropColumn('database_scope');
            });
        }

        if ($schema->hasColumn('api_configs', 'database_scope')) {
            $schema->table('api_configs', function (Blueprint $table): void {
                $table->dropColumn('database_scope');
            });
        }
    }
};
