<?php

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

    /** @var list<int|string> */
    public array $selected = [];

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['name', 'cost_input', 'cost_output', 'context_window'])) {
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
        if ($cost === null) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format((float) $cost, 8, '.', ''), '0'), '.');

        return '$'.($formatted === '' || $formatted === '-0' ? '0' : $formatted);
    }

    public function formatModalities(mixed $modalities): string
    {
        if (! is_array($modalities)) {
            return '—';
        }

        $input = array_key_exists('input', $modalities) && is_array($modalities['input'])
            ? $modalities['input']
            : $modalities;
        $output = array_key_exists('output', $modalities) && is_array($modalities['output'])
            ? $modalities['output']
            : [];

        return implode(', ', $input).' -> '.implode(', ', $output);
    }

    public function with(): array
    {
        return [
            'provider' => AiProvider::query()
                ->where('slug', $this->slug)
                ->firstOrFail(),
            'models' => AiModel::query()
                ->whereHas('provider', fn (Builder $query) => $query->where('slug', $this->slug))
                ->when(
                    $this->search !== '',
                    fn (Builder $query) => $query->where(function (Builder $query) {
                        $query
                            ->where('name', 'like', '%'.$this->search.'%')
                            ->orWhere('model_key', 'like', '%'.$this->search.'%');
                    }),
                )
                ->orderBy($this->sortField, $this->sortDirection)
                ->paginate(15),
        ];
    }
}; ?>

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

    <flux:table :paginate="$models">
        <flux:table.columns>
            <flux:table.column>
                <flux:checkbox wire:model.live="selectAll" />
            </flux:table.column>
            <flux:table.column sortable :sorted="$sortField === 'name'" :direction="$sortDirection" wire:click="sortBy('name')">Model</flux:table.column>
            <flux:table.column sortable :sorted="$sortField === 'cost_input'" :direction="$sortDirection" wire:click="sortBy('cost_input')">Input price</flux:table.column>
            <flux:table.column sortable :sorted="$sortField === 'cost_output'" :direction="$sortDirection" wire:click="sortBy('cost_output')">Output price</flux:table.column>
            <flux:table.column sortable :sorted="$sortField === 'context_window'" :direction="$sortDirection" wire:click="sortBy('context_window')">Context</flux:table.column>
            <flux:table.column>Modalities</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($models as $model)
                <flux:table.row :key="$model->id">
                    <flux:table.cell>
                        <flux:checkbox wire:model.live="selected" value="{{ $model->id }}" />
                    </flux:table.cell>

                    <flux:table.cell>
                        <div class="font-medium">{{ $model->name }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $model->model_key }}</div>
                    </flux:table.cell>

                    <flux:table.cell class="tabular-nums">{{ $this->formatCost($model->cost_input) }}</flux:table.cell>

                    <flux:table.cell class="tabular-nums">{{ $this->formatCost($model->cost_output) }}</flux:table.cell>

                    <flux:table.cell class="tabular-nums">
                        {{ $model->context_window !== null ? number_format((float) $model->context_window) : '—' }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $this->formatModalities($model->modalities) }}
                        </span>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
