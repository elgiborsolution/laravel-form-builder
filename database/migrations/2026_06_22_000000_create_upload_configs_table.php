<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(DatabaseConnection::name())->create('upload_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 150)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('endpoint', 255)->unique();
            $table->string('upload_path', 255)->default('uploads');
            $table->unsignedInteger('max_file_size')->nullable()->comment('Kilobytes');
            $table->json('allowed_extensions')->nullable();
            $table->boolean('multiple')->default(false);
            $table->json('middlewares')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(DatabaseConnection::name())->dropIfExists('upload_configs');
    }
};
