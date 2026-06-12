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
            if (! Schema::connection(DatabaseConnection::name())->hasColumn('data_sources', 'custom_parameters')) {
                $table->json('custom_parameters')->nullable()->after('custom_query');
            }
        });
    }

    public function down()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        if ($schema->hasColumn('data_sources', 'custom_parameters')) {
            $schema->table('data_sources', function (Blueprint $table) {
                $table->dropColumn('custom_parameters');
            });
        }
    }
};
