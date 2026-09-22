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
            $table->string('model_key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('family')->nullable()->index();
            $table->json('modalities')->nullable();
            $table->string('type', 30)->nullable();

            $table->unsignedBigInteger('context_window')->nullable()->index();
            $table->unsignedBigInteger('limit_input')->nullable();
            $table->unsignedBigInteger('max_output_tokens')->nullable();

            $table->boolean('reasoning')->default(false)->index();
            $table->json('reasoning_options')->nullable();
            $table->string('reasoning_default_effort', 20)->nullable();
            $table->json('reasoning_efforts')->nullable();
            $table->boolean('reasoning_mandatory')->default(false);
            $table->boolean('reasoning_interleaved')->nullable();

            $table->boolean('structured_output')->default(false);
            $table->boolean('temperature')->default(false);
            $table->boolean('tool_call')->default(false)->index();
            $table->boolean('attachment')->default(false);
            $table->boolean('open_weights')->default(false);

            $table->decimal('cost_input', 12, 6)->nullable();
            $table->decimal('cost_output', 12, 6)->nullable();
            $table->decimal('cost_cache_read', 12, 6)->nullable();
            $table->decimal('cost_cache_write', 12, 6)->nullable();
            $table->decimal('cost_input_audio', 12, 6)->nullable();
            $table->decimal('cost_output_audio', 12, 6)->nullable();
            $table->decimal('cost_reasoning', 12, 6)->nullable();
            $table->json('cost_context_over_200k')->nullable();
            $table->json('cost_tiers')->nullable();

            $table->json('experimental')->nullable();
            $table->json('provider_overrides')->nullable();

            $table->json('supported_parameters')->nullable();
            $table->json('default_parameters')->nullable();
            $table->json('benchmarks')->nullable();
            $table->json('pricing')->nullable();
            $table->string('canonical_slug')->nullable()->index();
            $table->json('links')->nullable();
            $table->json('alias_target')->nullable();

            $table->string('status', 30)->nullable()->index();
            $table->date('release_date')->nullable();
            $table->date('last_updated')->nullable();
            $table->string('knowledge', 30)->nullable();
            $table->string('source', 20)->default('all')->index();
            $table->timestamps();

            $table->unique(['provider_id', 'model_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
