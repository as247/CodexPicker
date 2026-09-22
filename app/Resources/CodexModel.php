<?php

namespace App\Resources;

use App\Models\AiModel;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use JsonSerializable;

/**
 * Builds a Codex-compatible model definition from an AiModel record.
 *
 * The JSON shape mirrors resources/json/codex-template.json. Defaults are taken
 * from the bundled template so that encoding a CodexModel always yields every
 * expected key, and the mapped AiModel data plus any explicit overrides are
 * applied on top of those defaults.
 */
/**
 * @extends Fluent<string, mixed>
 */
class CodexModel extends JsonResource
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public function __construct(AiModel $aiModel, array $overrides = [])
    {
        // Per-model overrides stored in ai_models.codex, then explicit call-site overrides win.
        $overrides = array_merge((array) ($aiModel->codex ?? []), $overrides);

        parent::__construct($this->buildAttributes($aiModel, $overrides));
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildAttributes(AiModel $aiModel, array $overrides): array
    {
        $attributes = $this->templateDefaults();

        Arr::set($attributes, 'slug', (string) $aiModel->model_key);
        Arr::set($attributes, 'display_name', (string) $aiModel->name);
        Arr::set($attributes, 'description', (string) ($aiModel->description ?? $aiModel->name));
        Arr::set($attributes, 'context_window', $aiModel->context_window);
        Arr::set($attributes, 'max_context_window', $aiModel->context_window);
        Arr::set($attributes, 'supports_parallel_tool_calls', (bool) $aiModel->tool_call);
        Arr::set($attributes, 'input_modalities', $this->inputModalities($aiModel));
        Arr::set($attributes, 'supports_image_detail_original', $this->supportsImageDetailOriginal($aiModel));
        Arr::set($attributes, 'default_reasoning_level', $this->defaultReasoningLevel($aiModel));
        Arr::set($attributes, 'supported_reasoning_levels', $this->supportedReasoningLevels($aiModel));
        Arr::set($attributes, 'supports_reasoning_summary_parameter', (bool) $aiModel->reasoning);
        Arr::set($attributes, 'supports_reasoning_summaries', (bool) $aiModel->reasoning);
        Arr::set($attributes, 'supports_search_tool', (bool) $aiModel->attachment);
        Arr::set($attributes, 'tool_mode', $this->toolMode($aiModel));
        Arr::set($attributes, 'default_reasoning_summary', $this->defaultReasoningSummary($aiModel));
        if (($attributes['truncation_policy'] ?? null) === null) {
            Arr::set($attributes, 'truncation_policy', $this->truncationPolicy($aiModel));
        }

        foreach ($overrides as $key => $value) {
            Arr::set($attributes, $key, $value);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function templateDefaults(): array
    {
        $path = resource_path('json/codex-template.json');

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException('Codex template not found at [resources/json/codex-template.json].');
        }

        /** @var array{models: list<array<string, mixed>>}|null $template */
        $template = json_decode($contents, true);

        if (($template['models'][0] ?? null) === null) {
            throw new \RuntimeException('Codex template does not contain a model definition.');
        }

        return $template['models'][0];
    }

    /**
     * @return list<string>
     */
    private function inputModalities(AiModel $aiModel): array
    {
        $modalities = $aiModel->modalities ?? [];

        if (array_is_list($modalities)) {
            $inputs = array_values(array_filter($modalities, fn ($value): bool => is_string($value)));
        } else {
            $inputs = $modalities['input'] ?? [];
        }

        $inputs = array_values(array_intersect($inputs, ['text', 'image', 'audio', 'video']));

        return $inputs !== [] ? $inputs : ['text'];
    }

    private function supportsImageDetailOriginal(AiModel $aiModel): bool
    {
        $modalities = $aiModel->modalities ?? [];

        $inputs = array_is_list($modalities)
            ? array_filter($modalities, fn ($value): bool => is_string($value))
            : ($modalities['input'] ?? []);

        return in_array('image', $inputs, true);
    }

    private function defaultReasoningLevel(AiModel $aiModel): string
    {
        if (! $aiModel->reasoning) {
            return 'none';
        }

        return (string) ($aiModel->reasoning_default_effort ?? 'medium');
    }

    /**
     * @return list<array{effort: string, description: string}>
     */
    private function supportedReasoningLevels(AiModel $aiModel): array
    {
        $efforts = (array) ($aiModel->reasoning_efforts ?? []);

        if ($efforts === []) {
            return [];
        }

        $descriptions = [
            'none' => 'No reasoning applied',
            'minimal' => 'Very brief reasoning',
            'low' => 'Fast responses with lighter reasoning',
            'medium' => 'Balances speed and reasoning depth for everyday tasks',
            'high' => 'Greater reasoning depth for complex problems',
            'xhigh' => 'Extra high reasoning depth for complex problems',
            'max' => 'Maximum reasoning depth for the hardest problems',
        ];

        return array_map(
            fn (string $effort): array => ['effort' => $effort, 'description' => $descriptions[$effort] ?? ucfirst($effort).' reasoning'],
            $efforts,
        );
    }

    private function toolMode(AiModel $aiModel): string
    {
        return $aiModel->tool_call ? 'unified' : 'none';
    }

    private function defaultReasoningSummary(AiModel $aiModel): string
    {
        $efforts = (array) ($aiModel->reasoning_efforts ?? []);

        if ($efforts === [] || ! $aiModel->reasoning) {
            return 'none';
        }

        return 'auto';
    }

    /**
     * @return array{mode: string, limit: int}|null
     */
    private function truncationPolicy(AiModel $aiModel): ?array
    {
        $context = $aiModel->context_window;

        if ($context === null) {
            return null;
        }

        return [
            'mode' => 'tokens',
            'limit' => (int) max(1_000, floor($context * 0.25)),
        ];
    }
}
