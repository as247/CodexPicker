<?php

namespace Tests\Feature;

use App\Models\AgentConfig;
use App\Models\AiModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConfigApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_provider_and_reasoning_efforts(): void
    {
        [$first, $second] = AiModel::factory()->count(2)->sequence(
            ['reasoning_efforts' => ['low', 'high'], 'reasoning' => true],
            ['reasoning_efforts' => ['medium', 'high', 'xhigh'], 'reasoning' => true],
        )->create();

        $config = AgentConfig::factory()->create([
            'provider_name' => 'OpenRouter',
            'provider_api' => 'https://openrouter.ai/api/v1',
            'model_ids' => [$first->id, $second->id],
        ]);

        $response = $this->getJson("/api/v1/config/{$config->id}");

        $response->assertOk();
        $response->assertJsonPath('provider.name', 'OpenRouter');
        $response->assertJsonPath('provider.api', 'https://openrouter.ai/api/v1');
        $response->assertJsonPath('reasoning_efforts', ['low', 'high', 'medium', 'xhigh']);
    }

    public function test_models_returns_codex_model_catalog(): void
    {
        $model = AiModel::factory()->create([
            'model_key' => 'openai/gpt-5.6-luna',
            'name' => 'GPT-5.6 Luna',
            'reasoning' => true,
            'reasoning_efforts' => ['low', 'medium', 'high'],
        ]);

        $config = AgentConfig::factory()->create([
            'model_ids' => [$model->id],
        ]);

        $response = $this->getJson("/api/v1/config/{$config->id}/models");

        $response->assertOk();
        $response->assertJsonPath('models.0.slug', 'openai/gpt-5.6-luna');
        $response->assertJsonPath('models.0.display_name', 'GPT-5.6 Luna');
    }
}
