<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Resources\CodexModelResource;
use Illuminate\Console\Command;

class ExportCodexModelsCommand extends Command
{
    protected $signature = 'ai:export-codex
        {--filter= : Comma-separated model keys to export, e.g. openai/gpt-5.6-luna}
        {--all : Kept for backwards compatibility; exports all matched models}
        {--path= : Output path, defaults to storage/app/codex-models.json}';

    protected $description = 'Export AI models to a Codex-compatible models JSON file';

    public function handle(): int
    {
        $query = AiModel::query();

        if ($filter = (string) $this->option('filter')) {
            $keys = array_map('trim', explode(',', $filter));
            $query->whereIn('model_id', $keys);
        }

        $models = $query->orderBy('model_id')->get();

        if ($models->isEmpty()) {
            $this->warn('No models matched. Nothing exported.');

            return self::FAILURE;
        }

        $payload = [
            'models' => $models
                ->map(fn (AiModel $model) => (new CodexModelResource($model))->toArray(request()))
                ->values()
                ->all(),
        ];

        $path = (string) ($this->option('path') ?: storage_path('app/codex-models.json'));

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info("Exported {$models->count()} model(s) to [{$path}].");

        return self::SUCCESS;
    }
}
