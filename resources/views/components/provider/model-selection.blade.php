@props([
    'selectedModels',
    'count' => 0,
])

<aside
    class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:sticky xl:top-24"
    aria-label="Model selection"
>
    <div class="mb-3 flex items-center justify-between gap-2">
        <flux:heading size="md">Model Selection</flux:heading>
        <flux:badge>{{ $count }}</flux:badge>
    </div>

    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Drag to reorder. Click x to remove.</p>

    @if ($count > 0)
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
                <x-provider.selected-model-item
                    :model="$model"
                    :index="$index"
                />
            @endforeach
        </ul>

        <flux:button variant="primary" class="mt-2">Build Codex Config</flux:button>
    @endif
</aside>
