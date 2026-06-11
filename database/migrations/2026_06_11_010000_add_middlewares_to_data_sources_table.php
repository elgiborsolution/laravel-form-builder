<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(DatabaseConnection::name())->table('data_sources', function (Blueprint $table): void {
            $table->json('middlewares')->nullable()->after('custom_query');
        });
    }

    public function down(): void
    {
        Schema::connection(DatabaseConnection::name())->table('data_sources', function (Blueprint $table): void {
            $table->dropColumn('middlewares');
        });
    }
};
