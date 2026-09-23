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
        if ($cost === null || $cost<0) {
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
                            {{ $model->context_window !== null ? number_format((float) $model->context_window) : '-' }}
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

    <aside
        class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:sticky xl:top-24"
        aria-label="Model selection"
    >
        <div class="mb-3 flex items-center justify-between gap-2">
            <flux:heading size="md">Model Selection</flux:heading>
            <flux:badge>{{ count($selected) }}</flux:badge>
        </div>

        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Drag to reorder. Click x to remove.</p>

        @if (count($selected) > 0)
            <button
                wire:click="$set('selected', [])"
                class="mb-3 text-xs font-medium text-red-600 hover:text-red-500 dark:text-red-400"
            >
                Clear all
            </button>
        @endif

        @if ($selectedModels->isEmpty())
            <div class="rounded-lg border border-dashed border-zinc-300 p-6 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                No models selected yet.
            </div>
        @else
            <ul
                x-data="{ dragIndex: null, overIndex: null }"
                class="space-y-2"
            >
                @foreach ($selectedModels as $index => $model)
                    <li
                        draggable="true"
                        class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                        :class="dragIndex === {{ $index }} ? 'opacity-40' : (overIndex === {{ $index }} && dragIndex !== null && dragIndex !== {{ $index }} ? 'ring-2 ring-blue-500' : '')"
                        @dragstart="dragIndex = {{ $index }}"
                        @dragend="dragIndex = null; overIndex = null"
                        @dragover.prevent="overIndex = {{ $index }}"
                        @drop.prevent="if (dragIndex !== null) { $wire.reorder(dragIndex, {{ $index }}) } dragIndex = null; overIndex = null"
                    >
                        <span class="cursor-grab select-none text-zinc-400 dark:text-zinc-500" title="Drag to reorder">⋮⋮</span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $model->name }}</span>
                            <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $model->model_key }}</span>
                        </span>

                        <button
                            wire:click="removeSelected({{ $model->id }})"
                            class="shrink-0 rounded p-1 text-zinc-400 hover:text-red-500"
                            title="Remove"
                            aria-label="Remove {{ $model->name }}"
                        >
                            x
                        </button>
                    </li>
                @endforeach
            </ul>

            <flux:button variant="primary" class="mt-2">Build Codex Config</flux:button>
        @endif
    </aside>
</div>
