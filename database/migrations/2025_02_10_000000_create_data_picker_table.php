<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration {
    public function up()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->create('data_pickers', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->json('filters')->nullable();
            $table->json('columns')->nullable();
            $table->json('params')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection(DatabaseConnection::name())->dropIfExists('data_pickers');
    }
};
