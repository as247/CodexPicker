<?php
use App\Models\AgentConfig;
use App\Models\AiModel;
use App\Models\AiProvider;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportStreaming\HandlesStreaming;

new class extends Component
{
    use HandlesStreaming;

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

    public string $apiKey = '';

    public bool $built = false;

    /** @var list<array{model: string, status: string}> */
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

    public function updatedProbeTest(bool $value): void
    {
        if (! $value) {
            $this->reset('apiKey');
        }
    }

    public function updatedApiKey(): void
    {
        $this->resetValidation('apiKey');
    }

    public function build(): void
    {
        $this->validate([
            'apiUrl' => ['required', 'string', 'url'],
        ]);

        $this->reset('results', 'liveCount', 'deadCount');

        if ($this->probeTest) {
            if (trim($this->apiKey) === '') {
                $this->addError('apiKey', 'API key is required to run the probe test.');

                return;
            }

            $models = AiModel::query()
                ->whereIn('id', $this->modelIds)
                ->get()
                ->keyBy(fn (AiModel $model): int => $model->id);

            $models = collect($this->modelIds)
                ->map(fn (int|string $id): ?AiModel => $models->get((int) $id))
                ->filter()
                ->values();

            foreach ($models as $model) {
                try {
                    $response = Http::withToken($this->apiKey)
                        ->timeout(20)
                        ->connectTimeout(2)
                        ->acceptJson()
                        ->post(rtrim($this->apiUrl, '/').'/chat/completions', [
                            'model' => $model->model_key,
                            'messages' => [
                                ['role' => 'user', 'content' => 'Reply with exactly: OK'],
                            ],
                            'max_tokens' => 10,
                        ]);

                    $ok = $response->successful();

                    $this->results[] = [
                        'model' => $model->model_key,
                        'status' => $ok ? 'live' : 'dead',
                    ];

                    if ($ok) {
                        $this->liveCount++;
                    } else {
                        $this->deadCount++;
                    }

                    $this->stream(
                        'probe-console',
                        view('livewire.provider.partials.probe-console-line', [
                            'model' => $model->model_key,
                            'status' => $ok ? 'live' : 'dead',
                        ])->render(),
                    );
                } catch (\Throwable $e) {
                    $this->results[] = [
                        'model' => $model->model_key,
                        'status' => 'dead',
                    ];

                    $this->deadCount++;

                    $this->stream(
                        'probe-console',
                        view('livewire.provider.partials.probe-console-line', [
                            'model' => $model->model_key,
                            'status' => 'dead',
                        ])->render(),
                    );
                }
            }

            $liveIds = collect($this->results)
                ->filter(fn (array $result): bool => $result['status'] === 'live')
                ->pluck('model')
                ->all();

            $liveModelIds = AiModel::query()
                ->whereIn('model_key', $liveIds)
                ->whereIn('id', $this->modelIds)
                ->pluck('id')
                ->all();

            if ($this->config !== null) {
                $this->config->update(['live_model_ids' => $liveModelIds]);
            }
        } else {
            $this->liveCount = count($this->modelIds);
            $this->deadCount = 0;
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
            'windows' => './setup.ps1 '.$configId,
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
            ->map(fn (int|string $id): ?string => $models->get((int) $id)?->model_key)
            ->filter()
            ->values();
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
                    <flux:input wire:model.live.debounce.300ms="apiUrl" placeholder="https://api.example.com/v1" />
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
                    <flux:input type="password" wire:model="apiKey" placeholder="sk-..." />
                    <flux:error name="apiKey" />
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
                    <div wire:stream="probe-console" class="max-h-40 overflow-y-auto space-y-0.5">
                        @foreach ($results as $result)
                            @include('livewire.provider.partials.probe-console-line', ['model' => $result['model'], 'status' => $result['status']])
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($built)
                <div>
                    <flux:heading size="sm">Setup command</flux:heading>

                    <div class="mt-2 flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800" x-data="{ tab: 'windows' }">
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
                >
                    <span wire:loading.remove wire:target="build">Build</span>
                    <span wire:loading wire:target="build" class="text-xs opacity-70">Probing models...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
