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
        $reasoningOptions = $model['reasoning_options'] ?? [];
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
            'input_modalities' => isset($model['modalities']['input']) ? json_encode($model['modalities']['input']) : null,
            'output_modalities' => isset($model['modalities']['output']) ? json_encode($model['modalities']['output']) : null,
            'context_window' => $limit['context'] ?? null,
            'max_output_tokens' => $limit['output'] ?? null,
            'supports_reasoning' => (bool) ($model['reasoning'] ?? false),
            'supports_tools' => (bool) ($model['tool_call'] ?? false),
            'supports_structured_output' => (bool) ($model['structured_output'] ?? false),
            'supports_temperature' => (bool) ($model['temperature'] ?? false),
            'open_weights' => (bool) ($model['open_weights'] ?? false),
            'input_price' => $cost['input'] ?? null,
            'output_price' => $cost['output'] ?? null,
            'cache_read_price' => $cost['cache_read'] ?? null,
            'cache_write_price' => isset($cost['cache_write']) && is_scalar($cost['cache_write']) ? $cost['cache_write'] : null,
            'reasoning_config' => json_encode([
                'options' => $reasoningOptions,
                'default_effort' => $defaultEffort,
                'supported_efforts' => $efforts,
            ]),
            'supported_parameters' => null,
            'pricing_rules' => isset($cost['tiers']) || isset($cost['context_over_200k']) ? json_encode([
                'tiers' => $cost['tiers'] ?? null,
                'context_over_200k' => $cost['context_over_200k'] ?? null,
            ]) : null,
            'release_date' => $model['release_date'] ?? null,
            'last_updated' => $model['last_updated'] ?? null,
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

        $created = 0;
        $merged = 0;

        DB::transaction(function () use ($models, $provider, &$created, &$merged): void {
            foreach ($models as $model) {
                $attributes = $this->mapOpenrouterModel($model, $provider->id);
                $existingModel = AiModel::query()
                    ->where('provider_id', $provider->id)
                    ->where('model_id', $attributes['model_id'])->first();

                if ($model !== null) {
                    $this->mergeIntoExisting($existingModel, $attributes);
                    $merged++;
                }else{
                    AiModel::query()->create($attributes);
                    $created++;
                }
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
            'input_modalities' => $inputModalities,
            'output_modalities' => $architecture['output_modalities'] ?? ['text'],
            'context_window' => $model['context_length'] ?? $topProvider['context_length'] ?? null,
            'max_output_tokens' => $topProvider['max_completion_tokens'] ?? null,
            // OpenRouter signals reasoning via mandatory reasoning or supported parameters.
            'supports_reasoning' => (bool) ($reasoning['mandatory'] ?? false)
                || in_array('reasoning', $parameters, true)
                || in_array('include_reasoning', $parameters, true),
            'supports_structured_output' => in_array('structured_outputs', $parameters, true),
            'supports_temperature' => in_array('temperature', $parameters, true),
            'supports_tools' => in_array('tools', $parameters, true),
            // Models published on Hugging Face are treated as open weights.
            'open_weights' => ($model['hugging_face_id'] ?? null) !== null,
            // OpenRouter pricing is per token; columns store per million tokens like all.json.
            'input_price' => isset($pricing['prompt']) ? (string) ((float) $pricing['prompt'] * 1_000_000) : null,
            'output_price' => isset($pricing['completion']) ? (string) ((float) $pricing['completion'] * 1_000_000) : null,
            'cache_read_price' => isset($pricing['input_cache_read']) ? (string) ((float) $pricing['input_cache_read'] * 1_000_000) : null,
            'cache_write_price' => $this->cacheWriteCost($pricing),
            'reasoning_config' => $reasoning !== [] || $efforts !== [] || $defaultEffort !== null ? array_merge($reasoning, [
                'supported_efforts' => $efforts,
                'default_effort' => $defaultEffort,
            ]) : null,
            'supported_parameters' => $parameters,
            'pricing_rules' => $pricing,
            'release_date' => isset($model['created'])
                ? Carbon::createFromTimestamp((int) $model['created'])->toDateString()
                : null,
            'last_updated' => null,
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
            if ($field === 'input_price' || $field === 'output_price') {
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
            'name', 'description', 'family', 'context_window', 'max_output_tokens',
            'input_modalities', 'output_modalities', 'supports_reasoning',
            'supports_tools', 'supports_structured_output', 'supports_temperature',
            'open_weights', 'input_price', 'output_price', 'cache_read_price',
            'cache_write_price', 'reasoning_config', 'supported_parameters',
            'pricing_rules', 'release_date', 'last_updated', 'source', 'updated_at',
        ];
    }

}
