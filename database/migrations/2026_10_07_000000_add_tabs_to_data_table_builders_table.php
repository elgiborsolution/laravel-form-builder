<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());
        if (! $schema->hasTable('data_table_builders')) {
            return;
        }

        $schema->table('data_table_builders', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('data_table_builders', 'type')) {
                $table->enum('type', ['table', 'tabs'])->default('table');
            }
            if (! $schema->hasColumn('data_table_builders', 'default_tab')) {
                $table->string('default_tab')->nullable();
            }
            if (! $schema->hasColumn('data_table_builders', 'tabs')) {
                $table->json('tabs')->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());
        if (! $schema->hasTable('data_table_builders')) {
            return;
        }

        $columns = array_values(array_filter(
            ['type', 'default_tab', 'tabs'],
            fn (string $column): bool => $schema->hasColumn('data_table_builders', $column)
        ));
        if ($columns !== []) {
            $schema->table('data_table_builders', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
