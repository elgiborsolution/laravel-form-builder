<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('data_pickers', function (Blueprint $table) {
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
        Schema::dropIfExists('data_pickers');
    }
};
