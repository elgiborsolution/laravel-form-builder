<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration {
    public function up()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('table_name');
            $table->boolean('use_custom_query')->default(false);
            $table->json('columns')->nullable();
            $table->text('custom_query')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection(DatabaseConnection::name())->dropIfExists('data_sources');
    }
};
