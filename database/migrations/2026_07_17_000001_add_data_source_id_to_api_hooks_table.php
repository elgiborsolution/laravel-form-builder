<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration {
    public function up()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('api_hooks', function (Blueprint $table) {
            $table->foreignId('data_source_id')
                ->nullable()
                ->after('api_config_id')
                ->constrained('data_sources')
                ->cascadeOnDelete();
        });
    }

    public function down()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('api_hooks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('data_source_id');
        });
    }
};
