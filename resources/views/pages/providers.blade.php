<?php

use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;
use Livewire\Attributes\Url;

new class extends Livewire\Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $sortField = 'name';

    #[Url]
    public string $sortDirection = 'asc';

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['name', 'models_count'])) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'providers' => AiProvider::query()
                ->withCount('models')
                ->when($this->search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->search.'%'))
                ->orderBy($this->sortField, $this->sortDirection)
                ->paginate(15),
        ];
    }
}; ?>

<div class="space-y-6">
        <flux:heading size="xl">AI Providers</flux:heading>

        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            placeholder="Search by provider name..."
        />

        <flux:table :paginate="$providers">
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortField === 'name'" :direction="$sortDirection" wire:click="sortBy('name')">Provider</flux:table.column>
                <flux:table.column sortable :sorted="$sortField === 'models_count'" :direction="$sortDirection" wire:click="sortBy('models_count')">Models</flux:table.column>
                <flux:table.column>Api</flux:table.column>
                <flux:table.column>Docs</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($providers as $provider)
                    <flux:table.row :key="$provider->id">
                        <flux:table.cell><flux:link :href="route('provider',['slug'=>$provider->slug])">{{ $provider->name }}</flux:link></flux:table.cell>
                        <flux:table.cell>{{ $provider->models_count }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($provider->api)
                                <div class="flex items-center">
                                    <span>{{$provider->api}}</span>

                                    <flux:button
                                        icon="document-duplicate"
                                        variant="ghost"
                                        size="sm"
                                        x-on:click="navigator.clipboard.writeText('{{$provider->api}}')"
                                    />
                                </div>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($provider->doc)
                                <flux:link href="{{ $provider->doc }}" target="_blank">Docs</flux:link>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
