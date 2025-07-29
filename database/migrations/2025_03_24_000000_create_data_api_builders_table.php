<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // Table for API configurations
        Schema::create('api_configs', function (Blueprint $table) {
            $table->id();
            $table->string('route_name', 250); // Route prefix
            $table->string('endpoint', 250);
            $table->string('method', 50);
            $table->text('description')->nullable();
            $table->json('params')->nullable();
            $table->boolean('enabled')->default(true); // Enable/disable API
            $table->timestamps();
        });

        // Table for affected table api configurations
        Schema::create('api_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_config_id')->references('id')->on('api_configs')->onDelete('cascade');
            $table->integer('parent_id')->default(0);
            $table->string('primary_key', 250)->nullable();
            $table->string('foreign_key', 250)->nullable();
            $table->string('table_name', 250);
            $table->json('data_params')->nullable();
            $table->timestamps();
        });
        
        // Table for permissions
        Schema::create('api_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_config_id')->references('id')->on('api_configs')->onDelete('cascade');
            $table->string('permission_string'); // e.g., "post.create", "user.delete"
            $table->timestamps();
        });
        // Table for hooks (before/after create, update, delete)
        Schema::create('api_hooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_config_id')->references('id')->on('api_configs')->onDelete('cascade');
            $table->string('action_type'); // before_create, after_create, etc.
            $table->string('listener_class'); // Reference to a Laravel event listener
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_configs');
        Schema::dropIfExists('api_tables');
        Schema::dropIfExists('api_permissions');
        Schema::dropIfExists('api_hooks');
    }
};
