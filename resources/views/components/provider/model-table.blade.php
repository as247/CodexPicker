@props([
    'models',
    'sortField',
    'sortDirection',
    'formatCost',
    'formatModalities',
])

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

                <flux:table.cell class="tabular-nums">{{ $formatCost($model->cost_input) }}</flux:table.cell>

                <flux:table.cell class="tabular-nums">{{ $formatCost($model->cost_output) }}</flux:table.cell>

                <flux:table.cell class="tabular-nums">
                    {{ $model->context_window !== null ? number_format((float) $model->context_window) : '-' }}
                </flux:table.cell>

                <flux:table.cell>
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $formatModalities($model->modalities) }}
                    </span>
                </flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
