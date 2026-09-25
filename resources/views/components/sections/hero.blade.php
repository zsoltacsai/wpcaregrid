@props(['trustIndicators', 'sites', 'updateRun'])

<section aria-labelledby="hero-title" class="pt-8 pb-16 sm:pt-12 sm:pb-20 lg:pt-16 lg:pb-24">
    <x-ui.container class="grid items-center gap-12 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:gap-16">
        <div>
            <p class="mb-4 text-xs font-semibold tracking-[0.12em] text-muted uppercase">{{ __('home.tagline') }}</p>

            <h1 id="hero-title" class="text-[34px] leading-[1.12] font-semibold tracking-[-0.02em] sm:text-[42px] lg:text-[48px] lg:leading-[1.1]">
                {{ __('home.hero.title') }}
            </h1>

            <p class="mt-6 max-w-xl text-lg leading-relaxed text-muted">{{ __('home.hero.text') }}</p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <x-ui.button href="#pricing" size="lg" icon="arrow-right">{{ __('home.hero.primary_cta') }}</x-ui.button>
                <x-ui.button href="#how-it-works" variant="secondary" size="lg">{{ __('home.hero.secondary_cta') }}</x-ui.button>
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

        <x-dashboard.dashboard-preview :sites="$sites" :update-run="$updateRun" />
    </x-ui.container>
</section>
