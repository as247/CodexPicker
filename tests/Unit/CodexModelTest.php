<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Resources\CodexModelResource;
use Tests\TestCase;

class CodexModelTest extends TestCase
{
    public function test_json_encode_matches_codex_template_shape(): void
    {
        $templateModel = json_decode(
            (string) file_get_contents(resource_path('json/codex-template.json')),
            true,
        )['models'][0];

        $aiModel = new AiModel([
            'model_key' => 'openai/gpt-5.6-luna',
            'name' => 'GPT-5.6 Luna',
            'description' => 'Fast and affordable agentic coding model.',
            'context_window' => 272000,
            'max_output_tokens' => 128000,
            'modalities' => ['input' => ['text', 'image'], 'output' => ['text']],
            'reasoning' => true,
            'reasoning_efforts' => ['low', 'medium', 'high', 'xhigh', 'max'],
            'reasoning_default_effort' => 'medium',
            'tool_call' => true,
            'attachment' => false,
        ]);

        $codex = new CodexModelResource($aiModel);
        $encoded = json_decode(json_encode($codex), true);

        $this->assertSame(array_keys($templateModel), array_keys($encoded));

        foreach ($templateModel as $key => $expected) {
            if ($key === 'truncation_policy') {
                continue;
            }

            $actual = $encoded[$key];

            if (is_array($expected)) {
                $this->assertSame($expected, $actual, "Array mismatch for key [{$key}].");
            }
        }

        $this->assertSame('openai/gpt-5.6-luna', $encoded['slug']);
        $this->assertSame('GPT-5.6 Luna', $encoded['display_name']);
        $this->assertSame(272000, $encoded['context_window']);
        $this->assertSame(272000, $encoded['max_context_window']);
        $this->assertSame(['text', 'image'], $encoded['input_modalities']);
        $this->assertTrue($encoded['supports_parallel_tool_calls']);
        $this->assertSame('medium', $encoded['default_reasoning_level']);
        $this->assertSame(
            ['mode' => 'tokens', 'limit' => 68000],
            $encoded['truncation_policy'],
        );
    }

    public function test_reasoning_efforts_come_from_database(): void
    {
        $aiModel = new AiModel([
            'model_key' => 'example/efforts',
            'name' => 'Efforts Model',
            'reasoning' => true,
            'reasoning_efforts' => ['none', 'low', 'high'],
            'reasoning_default_effort' => 'low',
        ]);

        $encoded = json_decode(json_encode(new CodexModelResource($aiModel)), true);

        $this->assertSame(
            ['none', 'low', 'high'],
            array_column($encoded['supported_reasoning_levels'], 'effort'),
        );
        $this->assertSame('low', $encoded['default_reasoning_level']);
        $this->assertSame('auto', $encoded['default_reasoning_summary']);
    }

    public function test_missing_fields_fall_back_to_template_defaults(): void
    {
        $aiModel = new AiModel([
            'model_key' => 'example/model',
            'name' => 'Example Model',
        ]);

        $encoded = json_decode(json_encode(new CodexModelResource($aiModel)), true);
        $templateModel = json_decode(
            (string) file_get_contents(resource_path('json/codex-template.json')),
            true,
        )['models'][0];

        $this->assertSame('example/model', $encoded['slug']);
        $this->assertSame('Example Model', $encoded['display_name']);
        $this->assertSame('Example Model', $encoded['description']);
        $this->assertSame($templateModel['truncation_policy'], $encoded['truncation_policy']);
        $this->assertSame($templateModel['max_context_window'], $encoded['max_context_window']);
        $this->assertSame('none', $encoded['tool_mode']);
    }

    public function test_non_reasoning_model_has_empty_reasoning_levels(): void
    {
        $aiModel = new AiModel([
            'model_key' => 'example/plain',
            'name' => 'Plain Model',
            'reasoning' => false,
        ]);

        $encoded = json_decode(json_encode(new CodexModelResource($aiModel)), true);

        $this->assertSame('none', $encoded['default_reasoning_level']);
        $this->assertSame(['text', 'image'], $encoded['input_modalities']);
    }
}
