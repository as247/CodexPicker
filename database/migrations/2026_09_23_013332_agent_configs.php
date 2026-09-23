<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('agent')->default('codex');
            $table->json('model_ids')->nullable();
            $table->json('live_model_ids')->nullable();
            $table->string('provider_name')->nullable();
            $table->text('provider_api')->nullable();
            $table->boolean('probe_models')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_configs');
    }
};
