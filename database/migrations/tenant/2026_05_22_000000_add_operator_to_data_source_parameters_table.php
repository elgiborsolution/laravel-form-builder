<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(DatabaseConnection::name())->table('data_source_parameters', function (Blueprint $table): void {
            $table->string('operator', 20)->default('=');
        });
    }

    public function down(): void
    {
        Schema::connection(DatabaseConnection::name())->table('data_source_parameters', function (Blueprint $table): void {
            $table->dropColumn('operator');
        });
    }
};
