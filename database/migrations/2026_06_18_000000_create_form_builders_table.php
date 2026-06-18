<?php

use ESolution\DataSources\Support\DatabaseConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->create('form_builders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 150)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('schema');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection(DatabaseConnection::name())->dropIfExists('form_builders');
    }
};

