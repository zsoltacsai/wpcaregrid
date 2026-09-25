@props(['trustIndicators', 'sites'])

<section aria-labelledby="hero-title" class="pt-8 pb-16 sm:pt-12 sm:pb-20 lg:pt-16 lg:pb-24">
    <x-ui.container class="grid items-center gap-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:gap-16">
        <div>
            <h1 id="hero-title" class="text-[36px] leading-[1.1] font-semibold tracking-[-0.02em] sm:text-5xl sm:leading-[1.08] lg:text-display">
                WordPress maintenance, without the hassle.
            </h1>

            <p class="mt-6 max-w-xl text-lg leading-relaxed text-muted">
                WordPress and WooCommerce maintenance, backups, security checks and updates — with functional checks after important updates.
            </p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <x-ui.button href="#pricing" size="lg" icon="arrow-right">View plans</x-ui.button>
                <x-ui.button href="#how-it-works" variant="secondary" size="lg">How it works</x-ui.button>
            </div>

            <ul class="mt-8 flex flex-col gap-3 text-sm text-ink sm:flex-row sm:flex-wrap sm:gap-x-6">
                @foreach ($trustIndicators as $indicator)
                    <li class="flex items-center gap-2">
                        <x-ui.icon :name="$indicator['icon']" class="size-4 text-accent-ink" />
                        {{ $indicator['label'] }}
                    </li>
                @endforeach
            </ul>
        </div>

        <x-dashboard.hero-preview :sites="$sites" />
    </x-ui.container>
</section>
