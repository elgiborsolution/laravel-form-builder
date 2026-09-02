<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(DatabaseConnection::name());
        $schema->create('import_staging_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_uuid')->unique();
            $table->foreignId('import_config_id')->constrained('import_configs')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('tenant_key', 255)->nullable()->index();
            $table->string('connection_name', 100)->nullable();
            $table->string('status', 30)->default('staged')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('dataset');
            $table->timestamps();
        });
        $schema->create('import_staging_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_uuid')->index();
            $table->foreignId('import_config_id')->constrained('import_configs')->cascadeOnDelete();
            $table->string('master_name', 255)->nullable()->index();
            $table->string('table_name', 255)->nullable()->index();
            $table->unsignedInteger('row_no');
            $table->string('parent_row_key', 255)->nullable()->index();
            $table->json('payload');
            $table->string('status', 20)->index();
            $table->json('errors')->nullable();
            $table->unsignedInteger('execution_order')->default(0);
            $table->timestamps();
            $table->index(['import_uuid', 'status']);
        });
    }
    public function down(): void { $schema = Schema::connection(DatabaseConnection::name()); $schema->dropIfExists('import_staging_records'); $schema->dropIfExists('import_staging_batches'); }
};
