<?php

namespace App\Resources;

use App\Models\AiModel;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * Maps an AiModel record to the Codex model definition format.
 *
 * resources/json/codex-template.json is the output contract: every key of the
 * template is preserved, and fields that AiModel does not have (or that are
 * null) fall back to the template default.
 *
 * @property AiModel $resource
 *
 * @mixin AiModel
 */
class CodexModelResource extends JsonResource
{
    public function __construct(AiModel $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $defaults = $this->templateDefaults();

        $mapped = [
            'slug' => $this->resource->model_key,
            'display_name' => $this->resource->name,
            'description' => $this->resource->description ?? $this->resource->name,
            'context_window' => $this->resource->context_window,
            'max_context_window' => $this->resource->context_window,
            'supports_parallel_tool_calls' => (bool) $this->resource->tool_call,
            'input_modalities' => $this->inputModalities(),
            'supports_image_detail_original' => $this->supportsImageDetailOriginal(),
            'default_reasoning_level' => $this->defaultReasoningLevel(),
            'supported_reasoning_levels' => $this->supportedReasoningLevels(),
            'supports_reasoning_summary_parameter' => (bool) $this->resource->reasoning,
            'supports_reasoning_summaries' => (bool) $this->resource->reasoning,
            'supports_search_tool' => (bool) $this->resource->attachment,
            'tool_mode' => $this->toolMode(),
            'default_reasoning_summary' => $this->defaultReasoningSummary(),
            'truncation_policy' => $this->truncationPolicy(),
        ];

        return $this->mergeWithDefaults($defaults, $mapped);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function mergeWithDefaults(array $defaults, array $mapped): array
    {
        $attributes = $defaults;

        foreach ($mapped as $key => $value) {
            if ($value === null || $value === []) {
                continue;
            }

            Arr::set($attributes, $key, $value);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function templateDefaults(): array
    {
        $contents = file_get_contents(resource_path('json/codex-template.json'));

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
    private function inputModalities(): array
    {
        $modalities = $this->resource->modalities;

        if ($modalities === null || $modalities === []) {
            return [];
        }

        if (array_is_list($modalities)) {
            $inputs = array_values(array_filter($modalities, fn ($value): bool => is_string($value)));
        } else {
            $inputs = $modalities['input'] ?? null;

            if (! is_array($inputs)) {
                $inputs = [];
            }
        }

        $inputs = array_values(array_intersect($inputs, ['text', 'image', 'audio', 'video']));

        return $inputs;
    }

    private function supportsImageDetailOriginal(): bool
    {
        $modalities = $this->resource->modalities;

        if ($modalities === null || $modalities === []) {
            return false;
        }

        $inputs = array_is_list($modalities)
            ? array_filter($modalities, fn ($value): bool => is_string($value))
            : ($modalities['input'] ?? null);

        if (! is_array($inputs)) {
            return false;
        }

        return in_array('image', $inputs, true);
    }

    private function defaultReasoningLevel(): ?string
    {
        if (! $this->resource->reasoning) {
            return 'none';
        }

        return $this->resource->reasoning_default_effort;
    }

    /**
     * @return list<array{effort: string, description: string}>
     */
    private function supportedReasoningLevels(): array
    {
        $efforts = (array) ($this->resource->reasoning_efforts ?? []);

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

    private function toolMode(): string
    {
        return $this->resource->tool_call ? 'unified' : 'none';
    }

    private function defaultReasoningSummary(): string
    {
        $efforts = (array) ($this->resource->reasoning_efforts ?? []);

        if ($efforts === [] || ! $this->resource->reasoning) {
            return 'none';
        }

        return 'auto';
    }

    /**
     * @return array{mode: string, limit: int}|null
     */
    private function truncationPolicy(): ?array
    {
        $context = $this->resource->context_window;

        if ($context === null) {
            return null;
        }

        return [
            'mode' => 'tokens',
            'limit' => (int) max(1_000, floor($context * 0.25)),
        ];
    }
}
