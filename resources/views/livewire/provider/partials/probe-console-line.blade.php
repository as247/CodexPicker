<span class="block truncate">
    @if ($status === 'live')
        <span class="text-green-500">OK</span>
    @else
        <span class="text-red-500">DEAD</span>
    @endif
    <span class="text-zinc-400">&middot; {{ $model }}</span>
</span>
