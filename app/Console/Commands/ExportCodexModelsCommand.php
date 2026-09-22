<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\CodexModel;
use Illuminate\Console\Command;

class ExportCodexModelsCommand extends Command
{
    protected $signature = 'ai:export-codex
        {--filter= : Comma-separated model keys to export, e.g. openai/gpt-5.6-luna}
        {--all : Export all models instead of only those with codex overrides}
        {--path= : Output path, defaults to storage/app/codex-models.json}';

    protected $description = 'Export AI models to a Codex-compatible models JSON file';

    public function handle(): int
    {
        $query = AiModel::query();

        if ($filter = (string) $this->option('filter')) {
            $keys = array_map('trim', explode(',', $filter));
            $query->whereIn('model_key', $keys);
        }

        if (! $this->option('all')) {
            $query->whereNotNull('codex');
        }

        $models = $query->orderBy('model_key')->get();

        if ($models->isEmpty()) {
            $this->warn('No models matched. Nothing exported.');

            return self::FAILURE;
        }

        if ($models->isEmpty()) {
            $this->warn('No models selected.');

            return self::FAILURE;
        }

        $payload = [
            'models' => $models
                ->map(fn (AiModel $model) => json_decode(json_encode(new CodexModel($model)), true))
                ->values()
                ->all(),
        ];

        $path = (string) ($this->option('path') ?: storage_path('app/codex-models.json'));

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info("Exported {$models->count()} model(s) to [{$path}].");

        return self::SUCCESS;
    }
}
