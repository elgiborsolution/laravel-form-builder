<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(DatabaseConnection::name())->table('api_tables', function (Blueprint $table): void {
            if (! Schema::connection(DatabaseConnection::name())->hasColumn('api_tables', 'use_soft_delete')) {
                $table->boolean('use_soft_delete')->default(false)->after('primary_key');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if ($schema->hasColumn('api_tables', 'use_soft_delete')) {
            $schema->table('api_tables', function (Blueprint $table): void {
                $table->dropColumn('use_soft_delete');
            });
        }
    }
};
