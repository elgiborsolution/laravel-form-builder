<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());
        if (! $schema->hasColumn('import_configs', 'custom_parameters')) {
            $schema->table('import_configs', function (Blueprint $table): void {
                $table->json('custom_parameters')->nullable()->after('template_metadata');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());
        if ($schema->hasColumn('import_configs', 'custom_parameters')) {
            $schema->table('import_configs', function (Blueprint $table): void {
                $table->dropColumn('custom_parameters');
            });
        }
    }
};
