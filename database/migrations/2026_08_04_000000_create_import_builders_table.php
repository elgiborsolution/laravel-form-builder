<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->create('import_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 150)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('endpoint', 255)->unique();
            $table->string('database_scope', 20)->default('central');
            $table->string('import_mode', 20)->default('UPSERT');
            $table->boolean('enabled')->default(true);
            $table->json('middlewares')->nullable();
            $table->string('before_execute_hook', 255)->nullable();
            $table->string('after_execute_hook', 255)->nullable();
            $table->string('template_disk', 100)->default('local');
            $table->string('template_path', 500)->nullable();
            $table->string('template_original_name', 255)->nullable();
            $table->string('template_type', 50)->nullable();
            $table->json('template_metadata')->nullable();
            $table->timestamps();
        });

        $schema->create('import_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_config_id')->references('id')->on('import_configs')->onDelete('cascade');
            $table->integer('parent_id')->default(0);
            $table->string('table_name', 250);
            $table->json('data_params')->nullable();
            $table->string('foreign_key', 250)->nullable();
            $table->string('primary_key', 250)->nullable();
            $table->string('key_update_delete', 250)->nullable();
            $table->string('child_update_key', 250)->nullable();
            $table->string('missing_child_strategy', 50)->nullable()->default('KEEP_EXISTING');
            $table->boolean('use_soft_delete')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->dropIfExists('import_tables');
        $schema->dropIfExists('import_configs');
    }
};
