<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(DatabaseConnection::name())->table('data_sources', function (Blueprint $table): void {
            if (! Schema::connection(DatabaseConnection::name())->hasColumn('data_sources', 'use_soft_delete')) {
                $table->boolean('use_soft_delete')->default(false)->after('use_custom_query');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if ($schema->hasColumn('data_sources', 'use_soft_delete')) {
            $schema->table('data_sources', function (Blueprint $table): void {
                $table->dropColumn('use_soft_delete');
            });
        }
    }
};
