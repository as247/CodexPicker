<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportAiCatalogCommand extends Command
{
    protected $signature = 'ai:import
        {--source=both : all, openrouter or both}
        {--path= : Base directory containing all.json and openrouter.json}';

    protected $description = 'Import AI providers and models from resources/json datasets';

    public function handle(): int
    {
        $source = (string) $this->option('source');

        if (! in_array($source, ['all', 'openrouter', 'both'], true)) {
            $this->error("Unknown source [{$source}]. Use all, openrouter or both.");

            return self::FAILURE;
        }

        $basePath = (string) ($this->option('path') ?: resource_path('json'));

        if (in_array($source, ['all', 'both'], true)) {
            $this->importAll($basePath.'/'.'all.json');
        }

        if (in_array($source, ['openrouter', 'both'], true)) {
            $this->importOpenrouter($basePath.'/'.'openrouter.json');
        }

        $this->info('Done. Providers: '.AiProvider::query()->count().', models: '.AiModel::query()->count().'.');

        return self::SUCCESS;
    }

    private function importAll(string $path): void
    {
        if (! is_file($path)) {
            $this->warn("Skipping all.json: file [{$path}] not found.");

            return;
        }

        $this->info("Importing providers and models from [{$path}]...");

        $providers = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($providers as $slug => $provider) {
            $providerModel = AiProvider::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $provider['name'] ?? Str::headline($slug),
                    'api' => $provider['api'] ?? null,
                    'doc' => $provider['doc'] ?? null,
                    'npm' => $provider['npm'] ?? null,
                    'env' => $provider['env'] ?? null,
                ],
            );

            $rows = [];
            $now = now();

            foreach ($provider['models'] as $modelKey => $model) {
                $rows[] = $this->mapAllModel($model, $providerModel->id, $now, $modelKey);
            }

            DB::transaction(function () use ($rows): void {
                foreach (array_chunk($rows, 500) as $chunk) {
                    AiModel::upsert(
                        $chunk,
                        ['provider_id', 'model_id'],
                        $this->upsertColumns(),
                    );
                }
            });

            $this->line(sprintf(
                '  %s: %d models',
                $slug,
                count($rows),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    private function mapAllModel(array $model, int $providerId, string $now, string $modelKey): array
    {
        $limit = (array) ($model['limit'] ?? []);
        $cost = (array) ($model['cost'] ?? []);
        $reasoningOptions = $model['reasoning_options'] ?? null;
        $efforts = collect((array) $reasoningOptions)
            ->filter(fn (array $option): bool => ($option['type'] ?? null) === 'effort')
            ->flatMap(fn (array $option): array => $option['values'] ?? [])
            ->values()
            ->all();
        $defaultEffort = in_array('medium', $efforts, true)
            ? 'medium'
            : ($efforts[0] ?? null);

        return [
            'provider_id' => $providerId,
            'model_id' => $modelKey,
            'name' => $model['name'] ?? $model['id'],
            'description' => $model['description'] ?? null,
            'family' => $model['family'] ?? null,
            'modalities' => isset($model['modalities']) ? json_encode($model['modalities']) : null,
            'type' => $model['type'] ?? null,
            'context_window' => $limit['context'] ?? null,
            'limit_input' => $limit['input'] ?? null,
            'max_output_tokens' => $limit['output'] ?? null,
            'reasoning' => (bool) ($model['reasoning'] ?? false),
            'reasoning_options' => $reasoningOptions !== null ? json_encode($reasoningOptions) : null,
            'reasoning_default_effort' => $defaultEffort,
            'reasoning_efforts' => $efforts !== [] ? json_encode($efforts) : null,
            'reasoning_mandatory' => false,
            'reasoning_interleaved' => isset($model['interleaved']) ? json_encode($model['interleaved']) : null,
            'structured_output' => (bool) ($model['structured_output'] ?? false),
            'temperature' => (bool) ($model['temperature'] ?? false),
            'tool_call' => (bool) ($model['tool_call'] ?? false),
            'attachment' => (bool) ($model['attachment'] ?? false),
            'open_weights' => (bool) ($model['open_weights'] ?? false),
            'cost_input' => $cost['input'] ?? null,
            'cost_output' => $cost['output'] ?? null,
            'cost_cache_read' => $cost['cache_read'] ?? null,
            'cost_cache_write' => isset($cost['cache_write']) && is_scalar($cost['cache_write']) ? $cost['cache_write'] : null,
            'cost_input_audio' => $cost['input_audio'] ?? null,
            'cost_output_audio' => $cost['output_audio'] ?? null,
            'cost_reasoning' => $cost['reasoning'] ?? null,
            'cost_context_over_200k' => isset($cost['context_over_200k']) ? json_encode($cost['context_over_200k']) : null,
            'cost_tiers' => isset($cost['tiers']) ? json_encode($cost['tiers']) : null,
            'experimental' => isset($model['experimental']) ? json_encode($model['experimental']) : null,
            'provider_overrides' => isset($model['provider']) ? json_encode($model['provider']) : null,
            'supported_parameters' => null,
            'default_parameters' => null,
            'benchmarks' => null,
            'pricing' => null,
            'canonical_slug' => null,
            'links' => null,
            'alias_target' => null,
            'status' => $model['status'] ?? null,
            'release_date' => $model['release_date'] ?? null,
            'last_updated' => $model['last_updated'] ?? null,
            'knowledge' => isset($model['knowledge']) ? (string) $model['knowledge'] : null,
            'source' => 'all',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function importOpenrouter(string $path): void
    {
        if (! is_file($path)) {
            $this->warn("Skipping openrouter.json: file [{$path}] not found.");

            return;
        }

        $this->info("Importing OpenRouter models from [{$path}]...");

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $models = $payload['data'] ?? [];

        $provider = $this->openrouterProvider();

        $existing = AiModel::query()
            ->where('provider_id', $provider->id)
            ->pluck('id', 'model_id');

        $created = 0;
        $merged = 0;

        DB::transaction(function () use ($models, $provider, $existing, &$created, &$merged): void {
            foreach ($models as $model) {
                $attributes = $this->mapOpenrouterModel($model, $provider->id);
                $modelKey = $attributes['model_id'];

                if (($id = $existing->get($modelKey)) !== null) {
                    $model = AiModel::query()->where('id', $id)->first();

                    if ($model !== null) {
                        $this->mergeIntoExisting($model, $attributes);
                    }

                    $merged++;

                    continue;
                }

                AiModel::query()->create($attributes);
                $created++;
            }
        });

        $this->line("  openrouter: {$created} created, {$merged} merged with all.json entries.");
    }

    private function openrouterProvider(): AiProvider
    {
        // OpenRouter may be absent from all.json; fall back to this definition.
        $fallback = [
            'slug' => 'openrouter',
            'name' => 'OpenRouter',
            'api' => 'https://openrouter.ai/api/v1',
            'doc' => 'https://openrouter.ai/models',
            'npm' => '@openrouter/ai-sdk-provider',
            'env' => ['OPENROUTER_API_KEY'],
        ];

        return AiProvider::query()->firstOrCreate(
            ['slug' => 'openrouter'],
            collect($fallback)->except('slug')->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    private function mapOpenrouterModel(array $model, int $providerId): array
    {
        $pricing = $model['pricing'] ?? [];
        $architecture = $model['architecture'] ?? [];
        $topProvider = $model['top_provider'] ?? [];
        $reasoning = $model['reasoning'] ?? [];
        $parameters = $model['supported_parameters'] ?? [];
        $efforts = array_values((array) ($reasoning['supported_efforts'] ?? []));
        $defaultEffort = $reasoning['default_effort'] ?? null;
        $inputModalities = $architecture['input_modalities'] ?? ['text'];

        return [
            'provider_id' => $providerId,
            'model_id' => $model['id'],
            'name' => $model['name'] ?? $model['id'],
            'description' => $model['description'] ?? null,
            'family' => null,
            'modalities' => [
                'input' => $inputModalities,
                'output' => $architecture['output_modalities'] ?? ['text'],
            ],
            'type' => null,
            'context_window' => $model['context_length'] ?? $topProvider['context_length'] ?? null,
            'limit_input' => null,
            'max_output_tokens' => $topProvider['max_completion_tokens'] ?? null,
            // OpenRouter signals reasoning via mandatory reasoning or supported parameters.
            'reasoning' => (bool) ($reasoning['mandatory'] ?? false)
                || in_array('reasoning', $parameters, true)
                || in_array('include_reasoning', $parameters, true),
            'reasoning_options' => null,
            'reasoning_default_effort' => $defaultEffort,
            'reasoning_efforts' => $efforts !== [] ? $efforts : null,
            'reasoning_mandatory' => (bool) ($reasoning['mandatory'] ?? false),
            'reasoning_interleaved' => null,
            'structured_output' => in_array('structured_outputs', $parameters, true),
            'temperature' => in_array('temperature', $parameters, true),
            'tool_call' => in_array('tools', $parameters, true),
            'attachment' => count(array_intersect($inputModalities, ['image', 'file', 'audio', 'video'])) > 0,
            // Models published on Hugging Face are treated as open weights.
            'open_weights' => ($model['hugging_face_id'] ?? null) !== null,
            // OpenRouter pricing is per token; columns store per million tokens like all.json.
            'cost_input' => isset($pricing['prompt']) ? (string) ((float) $pricing['prompt'] * 1_000_000) : null,
            'cost_output' => isset($pricing['completion']) ? (string) ((float) $pricing['completion'] * 1_000_000) : null,
            'cost_cache_read' => isset($pricing['input_cache_read']) ? (string) ((float) $pricing['input_cache_read'] * 1_000_000) : null,
            'cost_cache_write' => $this->cacheWriteCost($pricing),
            'cost_input_audio' => $this->scaledPrice($pricing['audio'] ?? null),
            'cost_output_audio' => $this->scaledPrice($pricing['audio_output'] ?? null),
            'cost_reasoning' => $this->scaledPrice($pricing['internal_reasoning'] ?? null),
            'cost_context_over_200k' => null,
            'cost_tiers' => null,
            'experimental' => null,
            'provider_overrides' => null,
            'supported_parameters' => $parameters,
            'default_parameters' => $model['default_parameters'] ?? null,
            'benchmarks' => $model['benchmarks'] ?? null,
            'pricing' => $pricing,
            'canonical_slug' => $model['canonical_slug'] ?? null,
            'links' => $model['links'] ?? null,
            'alias_target' => $model['alias_target'] ?? null,
            'status' => $this->openrouterStatus($model),
            'release_date' => isset($model['created'])
                ? Carbon::createFromTimestamp((int) $model['created'])->toDateString()
                : null,
            'last_updated' => null,
            'knowledge' => isset($model['knowledge_cutoff']) ? (string) $model['knowledge_cutoff'] : null,
            'source' => 'openrouter',
        ];
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function cacheWriteCost(array $pricing): ?string
    {
        $write = $pricing['input_cache_write'] ?? $pricing['input_cache_write_1h'] ?? null;

        return $write !== null ? (string) ((float) $write * 1_000_000) : null;
    }

    private function scaledPrice(mixed $price): ?string
    {
        return $price !== null ? (string) ((float) $price * 1_000_000) : null;
    }

    /**
     * Fill only null fields from the all.json row; OpenRouter stays the
     * authoritative source for its own pricing and capabilities.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mergeIntoExisting(AiModel $model, array $attributes): void
    {
        $updates = [];

        foreach ($attributes as $field => $value) {
            if (in_array($field, ['provider_id', 'model_id', 'source'], true)) {
                continue;
            }

            // OpenRouter is the authoritative source for its own data.
            if ($field === 'cost_input' || $field === 'cost_output') {
                if ($value !== null) {
                    $updates[$field] = $value;
                }

                continue;
            }

            if ($model->{$field} === null && $value !== null) {
                $updates[$field] = $value;
            }
        }

        $updates['updated_at'] = now();

        $model->fill($updates)->save();
    }

    /**
     * @return list<string>
     */
    private function upsertColumns(): array
    {
        return [
            'name', 'description', 'family', 'modalities', 'type',
            'context_window', 'limit_input', 'max_output_tokens',
            'reasoning', 'reasoning_options', 'reasoning_default_effort',
            'reasoning_efforts', 'reasoning_mandatory', 'reasoning_interleaved',
            'structured_output', 'temperature', 'tool_call', 'attachment',
            'open_weights', 'cost_input', 'cost_output', 'cost_cache_read',
            'cost_cache_write', 'cost_input_audio', 'cost_output_audio',
            'cost_reasoning', 'cost_context_over_200k', 'cost_tiers',
            'experimental', 'provider_overrides', 'supported_parameters',
            'default_parameters', 'benchmarks', 'pricing', 'canonical_slug',
            'links', 'alias_target', 'status', 'release_date', 'last_updated',
            'knowledge', 'source', 'updated_at',
        ];
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function openrouterStatus(array $model): ?string
    {
        $expiration = $model['expiration_date'] ?? null;

        if ($expiration !== null && Carbon::parse($expiration)->isPast()) {
            return 'deprecated';
        }

        return null;
    }
}
