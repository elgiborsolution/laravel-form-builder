<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ESolution\DataSources\Support\DatabaseConnection;

return new class extends Migration {
    public function up()
    {
        $schema = Schema::connection(DatabaseConnection::name());

        $schema->create('data_source_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained('data_sources')->onDelete('cascade');
            $table->string('param_name');
            $table->enum('param_type', ['string', 'integer', 'boolean', 'date', 'float']);
            $table->string('param_default_value')->nullable();
            $table->string('operator', 20)->default('=');
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::connection(DatabaseConnection::name())->dropIfExists('data_source_parameters');
    }
};
