<x-layouts::app :title="__('AI model providers')">
    @php
        $providers = [
            ['slug' => 'alibaba', 'name' => 'Alibaba', 'mark' => '阿', 'category' => 'Open models', 'description' => 'Explore the Qwen family and Alibaba Cloud models.', 'color' => 'from-orange-400/20 to-amber-500/5', 'accent' => 'text-orange-300', 'icon' => 'globe-asia-australia'],
            ['slug' => 'deepseek', 'name' => 'DeepSeek', 'mark' => '深', 'category' => 'Reasoning', 'description' => 'Find capable models built for code and reasoning.', 'color' => 'from-sky-400/20 to-blue-500/5', 'accent' => 'text-sky-300', 'icon' => 'sparkles'],
            ['slug' => 'openrouter', 'name' => 'OpenRouter', 'mark' => 'OR', 'category' => 'Model marketplace', 'description' => 'Compare models from a broad network of providers.', 'color' => 'from-violet-400/20 to-fuchsia-500/5', 'accent' => 'text-violet-300', 'icon' => 'arrows-right-left'],
            ['slug' => 'xiaomi', 'name' => 'Xiaomi', 'mark' => 'mi', 'category' => 'Foundation models', 'description' => 'Browse Xiaomi models, pricing, and capabilities.', 'color' => 'from-rose-400/20 to-red-500/5', 'accent' => 'text-rose-300', 'icon' => 'cpu-chip'],
            ['slug' => 'zai', 'name' => 'Z.ai', 'mark' => 'Z', 'category' => 'Intelligent models', 'description' => 'Discover GLM models from Z.ai.', 'color' => 'from-emerald-400/20 to-teal-500/5', 'accent' => 'text-emerald-300', 'icon' => 'command-line'],
        ];
    @endphp

    <div class="relative isolate overflow-hidden rounded-3xl border border-white/10 bg-[#101319] text-white shadow-2xl shadow-black/20">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 overflow-hidden">
            <div class="absolute -right-24 -top-36 size-[32rem] rounded-full bg-cyan-400/[0.09] blur-[100px]"></div>
            <div class="absolute -left-40 top-52 size-[30rem] rounded-full bg-indigo-500/[0.08] blur-[110px]"></div>
            <div class="absolute inset-0 opacity-[0.14] [background-image:linear-gradient(rgba(255,255,255,.09)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.09)_1px,transparent_1px)] [background-size:56px_56px] [mask-image:linear-gradient(to_bottom,black,transparent_70%)]"></div>
        </div>

        <section class="px-6 pb-16 pt-14 sm:px-10 sm:pb-20 sm:pt-20 lg:px-14 lg:pt-24">
            <div class="mx-auto max-w-4xl text-center">
                <flux:badge color="zinc" class="mb-6 border border-white/10 bg-white/[0.04] text-zinc-300">
                    <span class="mr-2 inline-block size-1.5 rounded-full bg-cyan-300 shadow-[0_0_10px_rgba(103,232,249,.8)]"></span>
                    Your AI model directory
                </flux:badge>

                <flux:heading level="1" class="!text-5xl !font-semibold !leading-[1.08] !tracking-[-0.055em] text-white sm:!text-6xl lg:!text-7xl">
                    Find the right model.<br class="hidden sm:block"> Make it yours.
                </flux:heading>

                <flux:subheading class="mx-auto mt-6 max-w-2xl !text-base !leading-7 text-zinc-400 sm:!text-lg">
                    Explore AI providers, compare model details, and build a Codex-ready configuration — all in one place.
                </flux:subheading>

                <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <flux:button variant="primary" :href="route('providers')" wire:navigate icon="squares-2x2" class="!rounded-xl !bg-cyan-300 !px-5 !py-3 !font-semibold !text-zinc-950 hover:!bg-cyan-200">
                        Explore providers
                    </flux:button>
                    <a href="#featured-providers" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.04] px-5 py-3 text-sm font-medium text-zinc-300 transition hover:border-white/20 hover:bg-white/[0.08] hover:text-white">
                        Browse featured models <flux:icon name="arrow-down" variant="micro" class="size-4" />
                    </a>
                </div>
            </div>

            <div class="mx-auto mt-14 flex max-w-3xl flex-wrap items-center justify-center gap-x-7 gap-y-3 border-t border-white/[0.08] pt-6 text-xs font-medium tracking-wide text-zinc-500 sm:mt-16">
                <span class="inline-flex items-center gap-2"><flux:icon name="magnifying-glass" variant="micro" class="size-4 text-zinc-400" /> Discover models</span>
                <span class="hidden size-1 rounded-full bg-zinc-700 sm:block"></span>
                <span class="inline-flex items-center gap-2"><flux:icon name="arrows-up-down" variant="micro" class="size-4 text-zinc-400" /> Compare capabilities</span>
                <span class="hidden size-1 rounded-full bg-zinc-700 sm:block"></span>
                <span class="inline-flex items-center gap-2"><flux:icon name="command-line" variant="micro" class="size-4 text-zinc-400" /> Configure Codex</span>
            </div>
        </section>

        <section id="featured-providers" class="border-t border-white/[0.08] bg-black/20 px-6 py-10 sm:px-10 sm:py-12 lg:px-14">
            <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <flux:heading level="2" size="lg" class="!font-semibold !tracking-tight text-white">Featured providers</flux:heading>
                    <p class="mt-1.5 text-sm text-zinc-500">A few good places to start exploring.</p>
                </div>
                <a href="{{ route('providers') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-cyan-300 transition hover:text-cyan-200">
                    View all providers <flux:icon name="arrow-right" variant="micro" class="size-4" />
                </a>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ($providers as $provider)
                    <a href="{{ route('provider', ['slug' => $provider['slug']]) }}" wire:navigate class="group relative flex min-h-48 flex-col overflow-hidden rounded-2xl border border-white/[0.09] bg-white/[0.025] p-5 transition duration-200 hover:-translate-y-1 hover:border-white/20 hover:bg-white/[0.055] focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300">
                        <div aria-hidden="true" class="pointer-events-none absolute -right-12 -top-14 size-36 rounded-full bg-gradient-to-br {{ $provider['color'] }} blur-2xl transition duration-300 group-hover:scale-125"></div>
                        <div class="relative flex items-center justify-between">
                            <span class="flex size-10 items-center justify-center rounded-xl border border-white/[0.08] bg-black/20 text-base font-semibold {{ $provider['accent'] }}">
                                {{ $provider['mark'] }}
                            </span>
                            <flux:icon name="arrow-up-right" variant="micro" class="size-4 text-zinc-600 transition group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-zinc-300" />
                        </div>
                        <div class="relative mt-5">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-zinc-500">{{ $provider['category'] }}</p>
                            <h3 class="mt-1 text-base font-semibold tracking-tight text-zinc-100">{{ $provider['name'] }}</h3>
                            <p class="mt-2 text-xs leading-5 text-zinc-500">{{ $provider['description'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        <footer class="flex flex-col gap-3 border-t border-white/[0.08] px-6 py-5 text-xs text-zinc-600 sm:flex-row sm:items-center sm:justify-between sm:px-10 lg:px-14">
            <span>CodexPicker <span class="text-zinc-700">·</span> A clearer way to choose your next model.</span>
            <span class="inline-flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-emerald-400"></span> Built for builders</span>
        </footer>
    </div>
</x-layouts::app>
