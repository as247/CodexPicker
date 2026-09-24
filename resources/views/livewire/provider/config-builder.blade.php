<?php
use App\Models\AgentConfig;
use App\Models\AiModel;
use App\Models\AiProvider;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?int $providerId = null;

    #[Locked]
    public ?string $configId = null;

    #[Locked]
    public ?string $providerName = null;

    #[Locked]
    public array $modelIds = [];

    public string $apiUrl = '';

    public bool $probeTest = false;

    public bool $built = false;

    /** @var list<array{model: string, status: string, latency_ms?: int|null, error?: string|null}> */
    public array $results = [];

    public int $liveCount = 0;

    public int $deadCount = 0;

    public ?AgentConfig $config = null;

    #[On('load-codex-config')]
    public function loadConfig(AiProvider $provider, AgentConfig $config): void
    {
        $this->providerId = $provider->id;
        $this->providerName = $provider->name;
        $this->apiUrl = $config->provider_api ?? $provider->api ?? '';
        $this->configId = $config->id;
        $this->config = $config;
        $this->modelIds = $config->model_ids ?? [];
        Flux::modal('build-codex-config')->show();
    }

    public function updatedApiUrl(string $value): void
    {
        if ($this->config !== null) {
            $this->config->update(['provider_api' => $value]);
        }
    }

    public function build(): void
    {
        $this->validate([
            'apiUrl' => ['required', 'string', 'url', 'regex:/^https?:\/\//i'],
        ]);

        $this->reset('results', 'liveCount', 'deadCount');
        $this->built = false;

        if ($this->probeTest) {
            $this->dispatch(
                'run-probe-test',
                models: $this->selectedModelLabels()->values()->all(),
                endpoint: $this->responsesEndpoint(),
            );

            return;
        }

        $liveModelIds = collect($this->modelIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->liveCount = count($liveModelIds);
        $this->deadCount = 0;

        if ($this->config !== null) {
            $this->config->update([
                'live_model_ids' => $liveModelIds,
            ]);
        }

        $this->built = true;
    }

    #[On('probe-test-results')]
    public function applyProbeResults(array $results = []): void
    {
        $this->results = [];
        $this->liveCount = 0;
        $this->deadCount = 0;

        foreach ($results as $result) {
            $ok = ($result['ok'] ?? false) === true;

            $this->results[] = [
                'model' => (string) ($result['model'] ?? ''),
                'status' => $ok ? 'live' : 'dead',
                'latency_ms' => isset($result['latencyMs']) ? (int) $result['latencyMs'] : null,
                'error' => $ok ? null : (isset($result['error']) ? (string) $result['error'] : null),
            ];

            if ($ok) {
                $this->liveCount++;
            } else {
                $this->deadCount++;
            }
        }

        $liveKeys = collect($this->results)
            ->filter(fn (array $result): bool => $result['status'] === 'live')
            ->pluck('model')
            ->all();

        $liveModelIds = AiModel::query()
            ->whereIn('model_id', $liveKeys)
            ->whereIn('id', $this->modelIds)
            ->pluck('id')
            ->all();

        if ($this->config !== null) {
            $this->config->update(['live_model_ids' => $liveModelIds]);
        }

        $this->built = true;
    }

    public function with(): array
    {
        return [
            'modelCount' => count($this->modelIds),
            'modelLabels' => $this->selectedModelLabels(),
            'setupCommands' => $this->setupCommands(),
        ];
    }

    protected function setupCommands(): array
    {
        $configId = $this->configId ?? '{config_id}';

        return [
            'windows' => sprintf('& ([scriptblock]::Create((irm "%s"))) %s',url('setup.ps1'),$configId),
            'linux' => './setup.sh '.$configId,
        ];
    }

    protected function selectedModelLabels(): Collection
    {
        $models = AiModel::query()
            ->whereIn('id', $this->modelIds)
            ->get()
            ->keyBy(fn (AiModel $model): int => $model->id);

        return collect($this->modelIds)
            ->map(fn (int|string $id): ?string => $models->get((int) $id)?->model_id)
            ->filter()
            ->values();
    }
    protected function responsesEndpoint(): string
    {
        $base = rtrim($this->apiUrl, '/');

        return preg_match('#/responses$#i', $base)
            ? $base
            : $base . '/responses';
    }
}
?>

<div>
    <flux:modal name="build-codex-config" class="w-full max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Build Codex Config</flux:heading>
                <flux:subheading>Configure the API endpoint and prepare your Codex configuration.</flux:subheading>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Provider name</flux:label>
                    <flux:input value="{{ $providerName }}" disabled />
                </flux:field>

                <flux:field>
                    <flux:label>API URL</flux:label>
                    <flux:input wire:model.live.debounce.500ms="apiUrl" placeholder="https://api.example.com/v1" />
                    <flux:error name="apiUrl" />
                </flux:field>
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <flux:label class="mb-0">Selected models</flux:label>
                    <flux:badge>{{ $modelCount }}</flux:badge>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    @foreach ($modelLabels->take(10) as $model)
                        <span class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            {{ $model }}
                        </span>
                    @endforeach

                    @if ($modelLabels->count() > 10)
                        <span class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-0.5 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                            ...
                        </span>
                    @endif
                </div>
            </div>

            <flux:checkbox
                wire:model.live="probeTest"
                label="Probe test"
                description="Check to verify each selected model is live before building the config."
            />

            @if ($probeTest)
                <flux:field>
                    <flux:label>API key</flux:label>
                    {{-- Kept out of Livewire state so the key stays in the browser. --}}
                    <input
                        id="probe-api-key"
                        type="password"
                        placeholder="sk-..."
                        class="block w-full rounded-md bg-white px-3 py-2 text-sm shadow-sm outline-none ring-1 ring-zinc-950/10 dark:bg-zinc-700/50 dark:ring-white/10 dark:text-white"
                    />
                </flux:field>
            @endif

            @if ($built && ! $probeTest)
                <div class="rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-400">
                    Config ready. {{ $liveCount }} models marked as live.
                </div>
            @endif

            @if ($probeTest)
                <div class="rounded-lg border border-zinc-200 bg-zinc-950 p-3 font-mono text-xs text-zinc-100 dark:border-zinc-700">
                    <div class="mb-2 flex items-center justify-between text-zinc-400">
                        <span>Probe console</span>
                        <span class="space-x-2">
                            <span class="text-green-500">{{ $liveCount }} live</span>
                            <span class="text-red-500">{{ $deadCount }} dead</span>
                        </span>
                    </div>
                    <div class="max-h-40 overflow-y-auto space-y-0.5">
                        @foreach ($results as $result)
                            @include('livewire.provider.partials.probe-console-line', ['model' => $result['model'], 'status' => $result['status'], 'latencyMs' => $result['latency_ms'] ?? null, 'error' => $result['error'] ?? null])
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($built)
                <div x-data="{ tab: 'windows' }">
                    <flux:heading size="sm">Setup command</flux:heading>

                    <div class="mt-2 flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800" >
                        <button type="button" x-on:click="tab = 'windows'" :class="tab === 'windows' ? 'bg-white shadow dark:bg-zinc-700' : 'hover:bg-zinc-200 dark:hover:bg-zinc-700'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium text-zinc-700 dark:text-zinc-300">Windows</button>
                        <button type="button" x-on:click="tab = 'linux'" :class="tab === 'linux' ? 'bg-white shadow dark:bg-zinc-700' : 'hover:bg-zinc-200 dark:hover:bg-zinc-700'" class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium text-zinc-700 dark:text-zinc-300">Linux</button>
                    </div>

                    <div class="mt-3 rounded-lg border border-zinc-200 bg-zinc-950 p-3 font-mono text-sm text-zinc-100 dark:border-zinc-700">
                        <div x-show="tab === 'windows'">{{ $setupCommands['windows'] }}</div>
                        <div x-show="tab === 'linux'" x-cloak>{{ $setupCommands['linux'] }}</div>
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-end gap-3">
                <flux:button variant="ghost" wire:close>Close</flux:button>

                <flux:button
                    variant="primary"
                    wire:click="build"
                    wire:loading.attr="disabled"
                    wire:target="build"
                    data-probe-button
                >
                    <span wire:loading.remove wire:target="build">Build</span>
                    <span wire:loading wire:target="build" class="text-xs opacity-70">Probing models...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>

@script
<script>
    $wire.$on('run-probe-test', async (event) => {
        const apiKeyInput = $wire.$el.querySelector('#probe-api-key');
        const apiKey = (apiKeyInput?.value ?? '').trim();

        if (!apiKey) {
            apiKeyInput?.focus();
            alert('API key is required to run the probe test.');
            return;
        }

        const {
            models = [],
            endpoint = '',
        } = event;

        if (!Array.isArray(models) || models.length === 0) {
            alert('No models available for probing.');
            return;
        }

        if (!endpoint) {
            alert('API endpoint is missing.');
            return;
        }

        const button = $wire.$el.querySelector('[data-probe-button]');
        const originalHtml = button?.innerHTML ?? null;

        const TIMEOUT_MS = 10_000;
        const CONCURRENCY = 4;

        if (button) {
            button.disabled = true;
            button.textContent = `Probing 0/${models.length}...`;
        }

        const results = new Array(models.length);
        let completed = 0;
        let nextIndex = 0;

        const probeModel = async (model) => {
            const startedAt = performance.now();
            const controller = new AbortController();

            const timeout = setTimeout(() => {
                controller.abort();
            }, TIMEOUT_MS);

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        Authorization: `Bearer ${apiKey}`,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        model,
                        input: 'Reply only: OK',
                        max_output_tokens: 2,
                        store: false,
                    }),
                    signal: controller.signal,
                });

                const data = await response.json().catch(() => null);

                const latencyMs = Math.round(
                    performance.now() - startedAt
                );

                if (response.ok) {
                    const incompleteReason =
                        data?.incomplete_details?.reason ?? null;

                    return {
                        model,
                        ok: true,
                        latencyMs,
                        error: incompleteReason
                            ? `HTTP ${response.status}; incomplete: ${incompleteReason}`
                            : null,
                    };
                }

                const message =
                    data?.error?.message ??
                    data?.error?.code ??
                    data?.message ??
                    response.statusText ??
                    `HTTP ${response.status}`;

                return {
                    model,
                    ok: false,
                    latencyMs,
                    error: `HTTP ${response.status}: ${String(message).slice(0, 200)}`,
                };
            } catch (error) {
                const latencyMs = Math.round(
                    performance.now() - startedAt
                );

                const message =
                    error?.name === 'AbortError'
                        ? `Timeout after ${TIMEOUT_MS / 1000}s`
                        : String(error?.message ?? error);

                return {
                    model,
                    ok: false,
                    latencyMs,
                    error: message.slice(0, 200),
                };
            } finally {
                clearTimeout(timeout);
            }
        };

        const worker = async () => {
            while (true) {
                const index = nextIndex++;

                if (index >= models.length) {
                    return;
                }

                results[index] = await probeModel(models[index]);

                completed++;

                if (button) {
                    button.textContent =
                        `Probing ${completed}/${models.length}...`;
                }
            }
        };

        try {
            const workerCount = Math.min(CONCURRENCY, models.length);

            await Promise.all(
                Array.from(
                    { length: workerCount },
                    () => worker()
                )
            );

            await $wire.$call(
                'applyProbeResults',
                results.filter(Boolean)
            );
        } finally {
            if (button) {
                button.disabled = false;

                if (originalHtml !== null) {
                    button.innerHTML = originalHtml;
                }
            }
        }
    });
</script>
@endscript
