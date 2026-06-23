<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('api_tables', function (Blueprint $table): void {
            if (! Schema::connection(DatabaseConnection::name())->hasColumn('api_tables', 'child_update_key')) {
                $table->string('child_update_key')->nullable()->after('primary_key');
            }

            if (! Schema::connection(DatabaseConnection::name())->hasColumn('api_tables', 'missing_child_strategy')) {
                $table->string('missing_child_strategy')->nullable()->default('KEEP_EXISTING')->after('child_update_key');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('api_tables', function (Blueprint $table): void {
            if (Schema::connection(DatabaseConnection::name())->hasColumn('api_tables', 'missing_child_strategy')) {
                $table->dropColumn('missing_child_strategy');
            }

            if (Schema::connection(DatabaseConnection::name())->hasColumn('api_tables', 'child_update_key')) {
                $table->dropColumn('child_update_key');
            }
        });
    }
};
