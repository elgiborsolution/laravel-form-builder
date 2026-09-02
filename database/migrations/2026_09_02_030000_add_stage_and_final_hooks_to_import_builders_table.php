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
        foreach (['before_stage_hook', 'after_stage_hook', 'before_final_hook', 'after_final_hook'] as $column) {
            if ($schema->hasColumn('import_configs', $column)) {
                continue;
            }

            $schema->table('import_configs', function (Blueprint $table) use ($column): void {
                $table->string($column, 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());
        $columns = array_values(array_filter(
            ['before_stage_hook', 'after_stage_hook', 'before_final_hook', 'after_final_hook'],
            fn (string $column): bool => $schema->hasColumn('import_configs', $column)
        ));
        if ($columns !== []) {
            $schema->table('import_configs', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
