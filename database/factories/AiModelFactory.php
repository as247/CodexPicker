<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModel>
 */
class AiModelFactory extends Factory
{
    protected $model = AiModel::class;

    public function definition(): array
    {
        return [
            'provider_id' => AiProvider::factory(),
            'model_key' => 'example/model',
            'name' => 'Example Model',
            'description' => 'Example model used for tests.',
            'context_window' => 128000,
            'reasoning' => false,
            'reasoning_mandatory' => false,
            'structured_output' => false,
            'temperature' => false,
            'tool_call' => false,
            'attachment' => false,
            'open_weights' => false,
            'source' => 'all',
        ];
    }
}
