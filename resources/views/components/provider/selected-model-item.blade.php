@props([
    'model',
    'index',
])

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
