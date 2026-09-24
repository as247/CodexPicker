<?php

use App\Models\AgentConfig;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;
use Livewire\Attributes\Url;

new class extends Livewire\Component
{
    use WithPagination;

    public string $slug = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sortField = 'name';

    #[Url]
    public string $sortDirection = 'asc';

    public bool $selectAll = false;

    public function createAgentConfig(): void
    {
        $config = AgentConfig::create([
            'provider_name' => $this->provider->name,
            'provider_api' => $this->provider->api,
            'model_ids' => array_map(intval(...), $this->selected),
        ]);
        $this->dispatch('load-codex-config', provider: $this->provider->id, config: $config->id);
    }

    /** @var list<int|string> */
    public array $selected = [];

    public function reorder(int $from, int $to): void
    {
        $selected = array_values($this->selected);

        if (! isset($selected[$from]) || $to < 0 || $to >= count($selected) || $from === $to) {
            return;
        }

        [$moved] = array_splice($selected, $from, 1);
        array_splice($selected, $to, 0, [$moved]);

        $this->selected = $selected;
    }

    public function removeSelected(int|string $id): void
    {
        $this->selected = array_values(array_filter(
            $this->selected,
            fn (int|string $selectedId): bool => (int) $selectedId !== (int) $id,
        ));

        $this->selectAll = false;
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['name', 'input_price', 'output_price', 'context_window'])) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? $this->models->getCollection()->pluck('id')->all()
            : [];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function formatCost(string|float|null $cost): string
    {
        if ($cost === null || $cost<0) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format((float) $cost, 8, '.', ''), '0'), '.');

        return '$'.($formatted === '' || $formatted === '-0' ? '0' : $formatted);
    }

    public function formatModalities(mixed $input, mixed $output): string
    {
        if (! is_array($input) && ! is_array($output)) {
            return '—';
        }

        $input = is_array($input) ? $input : [];
        $output = is_array($output) ? $output : [];

        return implode(', ', $input).' -> '.implode(', ', $output);
    }
    #[\Livewire\Attributes\Computed]
    public function provider(){
        return AiProvider::query()
            ->where('slug', $this->slug)
            ->firstOrFail();
    }

    public function with(): array
    {
        return [
            'provider' => $this->provider,
            'models' => AiModel::query()
                ->whereHas('provider', fn (Builder $query) => $query->where('slug', $this->slug))
                ->when(
                    $this->search !== '',
                    fn (Builder $query) => $query->where(function (Builder $query) {
                        $query
                            ->where('name', 'like', '%'.$this->search.'%')
                            ->orWhere('model_id', 'like', '%'.$this->search.'%');
                    }),
                )
                ->orderBy($this->sortField, $this->sortDirection)
                ->paginate(15),
            'selectedModels' => $this->selected === []
                ? collect()
                : AiModel::query()
                    ->whereIn('id', $this->selected)
                    ->get()
                    ->sortBy(fn (AiModel $model): int => (int) array_search((int) $model->id, array_map(intval(...), $this->selected)))
                    ->values(),
        ];
    }
}; ?>

<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="space-y-6">
        <div class="space-y-1">
            <flux:heading size="xl">{{ $provider->name }}</flux:heading>
            <flux:subheading>{{ $models->total() }} models</flux:subheading>
        </div>

        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            placeholder="Search by model name or key..."
        />

        <x-provider.model-table
            :models="$models"
            :sort-field="$sortField"
            :sort-direction="$sortDirection"
            :format-cost="$this->formatCost(...)"
            :format-modalities="$this->formatModalities(...)"
        />
    </div>

    <x-provider.model-selection
        :selected-models="$selectedModels"
        :count="count($selected)"
    />

    <livewire:provider.config-builder wire:key="config-builder" />
</div>
