<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration {
    public function up()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->table('data_sources', function (Blueprint $table) {
            if (!Schema::connection(DatabaseConnection::name())->hasColumn('data_sources', 'response_type')) {
                $table->string('response_type', 20)->default('array')->after('middlewares');
            }
        });
    }

    public function down()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if ($schema->hasColumn('data_sources', 'response_type')) {
            $schema->table('data_sources', function (Blueprint $table) {
                $table->dropColumn('response_type');
            });
        }
    }
};
