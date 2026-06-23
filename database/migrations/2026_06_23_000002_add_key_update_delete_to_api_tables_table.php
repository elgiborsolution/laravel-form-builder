<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if (! $schema->hasColumn('api_tables', 'key_update_delete')) {
            $schema->table('api_tables', function (Blueprint $table): void {
                $table->string('key_update_delete', 250)->nullable()->after('primary_key');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if ($schema->hasColumn('api_tables', 'key_update_delete')) {
            $schema->table('api_tables', function (Blueprint $table): void {
                $table->dropColumn('key_update_delete');
            });
        }
    }
};
