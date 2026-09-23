<?php

namespace Database\Factories;

use App\Models\AgentConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentConfig>
 */
class AgentConfigFactory extends Factory
{
    protected $model = AgentConfig::class;

    public function definition(): array
    {
        return [
            'agent' => 'codex',
            'model_ids' => null,
            'provider_name' => null,
            'provider_api' => null,
            'probe_models' => false,
        ];
    }
}
