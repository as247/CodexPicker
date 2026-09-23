<span class="block truncate">
    @if ($status === 'live')
        <span class="text-green-500">OK</span>
    @else
        <span class="text-red-500">DEAD</span>
    @endif
    <span class="text-zinc-400">&middot; {{ $model }}</span>
    @if (! empty($latencyMs))
        <span class="text-zinc-500">{{ $latencyMs }}ms</span>
    @endif
    @if (! empty($error))
        <span class="text-red-400/80 truncate">{{ $error }}</span>
    @endif
</span>
