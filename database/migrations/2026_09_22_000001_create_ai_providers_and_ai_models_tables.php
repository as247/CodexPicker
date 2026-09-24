<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('api')->nullable();
            $table->string('doc')->nullable();
            $table->string('npm')->nullable();
            $table->json('env')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            // Identity
            $table->string('model_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('family')->nullable()->index();
            // Limits
            $table->unsignedBigInteger('context_window')->nullable()->index();
            $table->unsignedBigInteger('max_output_tokens')->nullable();
            // Modalities
            $table->json('input_modalities')->nullable();
            $table->json('output_modalities')->nullable();
            // Capabilities
            $table->boolean('supports_reasoning')->default(false)->index();
            $table->boolean('supports_tools')->default(false)->index();
            $table->boolean('supports_structured_output')->default(false)->index();
            $table->boolean('supports_temperature')->default(false)->index();
            $table->boolean('open_weights')->default(false)->index();
            // Pricing: USD / 1M tokens
            $table->decimal('input_price', 20, 8)->nullable();
            $table->decimal('output_price', 20, 6)->nullable();
            $table->decimal('cache_read_price', 20, 8)->nullable();
            $table->decimal('cache_write_price', 20, 6)->nullable();


            // Flexible configuration
            $table->json('reasoning_config')->nullable();
            $table->json('supported_parameters')->nullable();
            $table->json('pricing_rules')->nullable();


            // Meta
            $table->date('release_date')->nullable();
            $table->date('last_updated')->nullable();

            $table->string('source', 20)->default('all')->index();
            $table->timestamps();

            $table->unique(['provider_id', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
