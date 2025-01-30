<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('data_source_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained('data_sources')->onDelete('cascade');
            $table->string('param_name');
            $table->enum('param_type', ['string', 'integer', 'boolean', 'date', 'float']);
            $table->string('param_default_value')->nullable();
            $table->boolean('is_required')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('data_source_parameters');
    }
};
