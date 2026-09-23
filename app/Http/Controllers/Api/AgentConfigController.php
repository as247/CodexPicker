<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentConfig;
use App\Models\AiModel;
use App\Resources\CodexModelResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class AgentConfigController extends Controller
{
    public function show(AgentConfig $config): JsonResponse
    {
        $models = $this->resolveModels($config);

        $reasoningEfforts = $models
            ->flatMap(fn (AiModel $model): array => (array) ($model->reasoning_efforts ?? []))
            ->filter(fn (string $effort): bool => $effort !== '')
            ->unique()
            ->values();

        return response()->json([
            'provider' => [
                'name' => $config->provider_name,
                'api' => $config->provider_api,
            ],
            'reasoning_efforts' => $reasoningEfforts,
        ]);
    }

    public function models(AgentConfig $config): JsonResponse
    {
        $models = $this->resolveModels($config);

        $payload = [
            'models' => $models
                ->map(fn (AiModel $model) => (new CodexModelResource($model))->toArray(request()))
                ->values()
                ->all(),
        ];

        return response()->json($payload);
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function resolveModels(AgentConfig $config): Collection
    {
        $modelIds = $config->model_ids ?? [];

        if ($modelIds === []) {
            return collect();
        }

        return AiModel::query()
            ->whereIn('id', $modelIds)
            ->get();
    }
}
