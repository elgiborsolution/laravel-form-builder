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
            $table->string('master_name', 255)->nullable()->after('parent_id');
            $table->string('import_mode', 20)->nullable()->after('master_name');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('import_tables', function (Blueprint $table): void {
            $table->dropColumn(['master_name', 'import_mode']);
        });
    }
};
