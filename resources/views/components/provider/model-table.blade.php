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
        <flux:table.column sortable :sorted="$sortField === 'input_price'" :direction="$sortDirection" wire:click="sortBy('input_price')">Input price</flux:table.column>
        <flux:table.column sortable :sorted="$sortField === 'output_price'" :direction="$sortDirection" wire:click="sortBy('output_price')">Output price</flux:table.column>
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
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $model->model_id }}</div>
                </flux:table.cell>

                <flux:table.cell class="tabular-nums">{{ $formatCost($model->input_price) }}</flux:table.cell>

                <flux:table.cell class="tabular-nums">{{ $formatCost($model->output_price) }}</flux:table.cell>

                <flux:table.cell class="tabular-nums">
                    {{ $model->context_window !== null ? number_format((float) $model->context_window) : '-' }}
                </flux:table.cell>

                <flux:table.cell>
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $formatModalities($model->input_modalities, $model->output_modalities) }}
                    </span>
                </flux:table.cell>
            </flux:table.row>
        @endforeach
    </flux:table.rows>
</flux:table>
