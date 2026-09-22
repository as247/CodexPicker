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
    /**
     * OpenRouter is not fully described in all.json, so it is defined here.
     */
    private const OPENROUTER_PROVIDER = [
        'slug' => 'openrouter',
        'name' => 'OpenRouter',
        'api' => 'https://openrouter.ai/api/v1',
        'doc' => 'https://openrouter.ai/models',
        'npm' => '@openrouter/ai-sdk-provider',
        'env' => ['OPENROUTER_API_KEY'],
    ];

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

            foreach ($provider['models'] ?? [] as $model) {
                $limit = $model['limit'] ?? [];
                $cost = $model['cost'] ?? [];
                $reasoningOptions = collect((array) ($model['reasoning_options'] ?? []))
                    ->filter(fn (array $option): bool => ($option['type'] ?? null) === 'effort')
                    ->flatMap(fn (array $option): array => $option['values'] ?? [])
                    ->values()
                    ->all();
                $defaultEffort = in_array('medium', $reasoningOptions, true)
                    ? 'medium'
                    : ($reasoningOptions[0] ?? null);

                $rows[] = [
                    'provider_id' => $providerModel->id,
                    'model_key' => $model['id'],
                    'name' => $model['name'] ?? $model['id'],
                    'description' => $model['description'] ?? null,
                    'family' => $model['family'] ?? null,
                    'context_window' => $limit['context'] ?? null,
                    'max_output_tokens' => $limit['output'] ?? null,
                    'modalities' => json_encode($model['modalities'] ?? null),
                    'reasoning' => (bool) ($model['reasoning'] ?? false),
                    'reasoning_mandatory' => false,
                    'reasoning_default_effort' => $defaultEffort,
                    'reasoning_efforts' => $reasoningOptions !== [] ? json_encode($reasoningOptions) : null,
                    'reasoning_options' => isset($model['reasoning_options']) ? json_encode($model['reasoning_options']) : null,
                    'supported_parameters' => null,
                    'structured_output' => (bool) ($model['structured_output'] ?? false),
                    'temperature' => (bool) ($model['temperature'] ?? false),
                    'tool_call' => (bool) ($model['tool_call'] ?? false),
                    'attachment' => (bool) ($model['attachment'] ?? false),
                    'open_weights' => (bool) ($model['open_weights'] ?? false),
                    'cost_input' => isset($cost['input']) ? (string) $cost['input'] : null,
                    'cost_output' => isset($cost['output']) ? (string) $cost['output'] : null,
                    'status' => $model['status'] ?? null,
                    'release_date' => $model['release_date'] ?? null,
                    'last_updated' => $model['last_updated'] ?? null,
                    'knowledge' => isset($model['knowledge']) ? (string) $model['knowledge'] : null,
                    'source' => 'all',
                    'raw' => json_encode($this->stripMapped($model, [
                        'id', 'name', 'description', 'family', 'attachment', 'reasoning',
                        'tool_call', 'release_date', 'last_updated', 'modalities',
                        'open_weights', 'limit', 'cost', 'knowledge', 'status',
                        'structured_output', 'temperature', 'reasoning_options',
                    ])),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($rows): void {
                foreach (array_chunk($rows, 500) as $chunk) {
                    AiModel::upsert(
                        $chunk,
                        ['provider_id', 'model_key'],
                        [
                            'name', 'description', 'family', 'context_window', 'max_output_tokens',
                            'modalities', 'reasoning', 'reasoning_mandatory', 'reasoning_default_effort',
                            'reasoning_efforts', 'reasoning_options', 'supported_parameters',
                            'structured_output', 'temperature', 'tool_call', 'attachment', 'open_weights',
                            'cost_input', 'cost_output', 'status', 'release_date', 'last_updated',
                            'knowledge', 'source', 'raw', 'updated_at',
                        ],
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

    private function importOpenrouter(string $path): void
    {
        if (! is_file($path)) {
            $this->warn("Skipping openrouter.json: file [{$path}] not found.");

            return;
        }

        $this->info("Importing OpenRouter models from [{$path}]...");

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $models = $payload['data'] ?? [];

        $provider = AiProvider::query()->updateOrCreate(
            ['slug' => self::OPENROUTER_PROVIDER['slug']],
            collect(self::OPENROUTER_PROVIDER)->except('slug')->all(),
        );

        $existing = AiModel::query()
            ->where('provider_id', $provider->id)
            ->pluck('id', 'model_key');

        $created = 0;
        $merged = 0;

        DB::transaction(function () use ($models, $provider, $existing, &$created, &$merged): void {
            foreach ($models as $model) {
                $attributes = $this->mapOpenrouterModel($model, $provider->id);
                $modelKey = $attributes['model_key'];

                if (($id = $existing->get($modelKey)) !== null) {
                    $this->mergeIntoExisting(AiModel::query()->findOrFail($id), $attributes);
                    $merged++;

                    continue;
                }

                AiModel::query()->create($attributes);
                $created++;
            }
        });

        $this->line("  openrouter: {$created} created, {$merged} merged with all.json entries.");
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
            'model_key' => $model['id'],
            'name' => $model['name'] ?? $model['id'],
            'description' => $model['description'] ?? null,
            'family' => null,
            'context_window' => $model['context_length'] ?? $topProvider['context_length'] ?? null,
            'max_output_tokens' => $topProvider['max_completion_tokens'] ?? null,
            'modalities' => json_encode([
                'input' => $inputModalities,
                'output' => $architecture['output_modalities'] ?? ['text'],
            ]),
            // OpenRouter signals reasoning via mandatory reasoning or supported parameters.
            'reasoning' => (bool) ($reasoning['mandatory'] ?? false)
                || in_array('reasoning', $parameters, true)
                || in_array('include_reasoning', $parameters, true),
            'reasoning_mandatory' => (bool) ($reasoning['mandatory'] ?? false),
            'reasoning_default_effort' => $defaultEffort,
            'reasoning_efforts' => $efforts !== [] ? json_encode($efforts) : null,
            'reasoning_options' => null,
            'supported_parameters' => json_encode($parameters),
            'structured_output' => in_array('structured_outputs', $parameters, true),
            'temperature' => in_array('temperature', $parameters, true),
            'tool_call' => in_array('tools', $parameters, true),
            'attachment' => count(array_intersect($inputModalities, ['image', 'file', 'audio', 'video'])) > 0,
            // Models published on Hugging Face are treated as open weights.
            'open_weights' => ($model['hugging_face_id'] ?? null) !== null,
            // OpenRouter pricing is per token; columns store per million tokens like all.json.
            'cost_input' => isset($pricing['prompt']) ? (string) ((float) $pricing['prompt'] * 1_000_000) : null,
            'cost_output' => isset($pricing['completion']) ? (string) ((float) $pricing['completion'] * 1_000_000) : null,
            'status' => $this->openrouterStatus($model),
            'release_date' => isset($model['created'])
                ? Carbon::createFromTimestamp((int) $model['created'])->toDateString()
                : null,
            'last_updated' => null,
            'knowledge' => isset($model['knowledge_cutoff']) ? (string) $model['knowledge_cutoff'] : null,
            'source' => 'openrouter',
            'raw' => json_encode(['openrouter' => $this->stripMapped($model, ['id'])]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Fill only null fields from the all.json row, and merge the OpenRouter
     * payload into raw so nothing from either source is lost.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mergeIntoExisting(AiModel $model, array $attributes): void
    {
        $rawAll = $model->raw ?? [];
        $rawOr = json_decode((string) $attributes['raw'], true) ?? [];

        $updates = [];

        foreach (['description', 'family', 'context_window', 'max_output_tokens', 'modalities',
            'reasoning', 'reasoning_mandatory', 'reasoning_default_effort', 'reasoning_efforts',
            'reasoning_options', 'supported_parameters', 'structured_output', 'temperature',
            'tool_call', 'attachment', 'open_weights', 'cost_input', 'cost_output',
            'status', 'release_date', 'last_updated', 'knowledge', ] as $field) {
            if ($model->{$field} === null && $attributes[$field] !== null) {
                $updates[$field] = $attributes[$field];
            }
        }

        // OpenRouter is the authoritative source for its own pricing.
        foreach (['cost_input', 'cost_output'] as $field) {
            if ($attributes[$field] !== null) {
                $updates[$field] = $attributes[$field];
            }
        }

        $updates['raw'] = array_merge($rawAll, $rawOr);
        $updates['updated_at'] = now();

        $model->fill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $model
     * @param  list<string>  $mapped
     * @return array<string, mixed>
     */
    private function stripMapped(array $model, array $mapped): array
    {
        return collect($model)->except($mapped)->all();
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
