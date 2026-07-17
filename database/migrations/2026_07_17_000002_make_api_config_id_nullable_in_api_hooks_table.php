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
            $table->foreignId('api_config_id')->nullable()->change();
        });
    }

    public function down()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('api_hooks', function (Blueprint $table) {
            $table->foreignId('api_config_id')->change();
        });
    }
};
