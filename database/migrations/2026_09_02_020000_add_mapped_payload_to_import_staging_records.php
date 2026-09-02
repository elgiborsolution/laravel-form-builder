<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(DatabaseConnection::name())->table('import_staging_records', function (Blueprint $table): void {
            $table->json('mapped_payload')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::connection(DatabaseConnection::name())->table('import_staging_records', function (Blueprint $table): void {
            $table->dropColumn('mapped_payload');
        });
    }
};
