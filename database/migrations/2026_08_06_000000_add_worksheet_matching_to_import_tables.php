<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('import_tables', function (Blueprint $table): void {
            $table->string('worksheet', 255)->nullable()->after('table_name');
            $table->string('parent_match_column', 255)->nullable()->after('worksheet');
            $table->string('child_match_column', 255)->nullable()->after('parent_match_column');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('import_tables', function (Blueprint $table): void {
            $table->dropColumn(['worksheet', 'parent_match_column', 'child_match_column']);
        });
    }
};
