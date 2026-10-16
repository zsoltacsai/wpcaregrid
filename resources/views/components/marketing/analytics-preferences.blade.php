<section
    id="analytics-preferences"
    data-analytics-preferences
    aria-labelledby="analytics-preferences-title"
    aria-live="polite"
    class="mx-auto my-8 hidden w-[min(100%-2rem,72rem)] rounded-md border border-line bg-surface p-5 shadow-raised md:p-6"
>
    <h2 id="analytics-preferences-title" tabindex="-1" class="text-lg font-semibold">{{ __('analytics.preferences.title') }}</h2>
    <p class="mt-2 max-w-3xl text-sm text-muted">{{ __('analytics.preferences.description') }}</p>
    <p class="mt-2 text-xs text-muted">{{ __('analytics.preferences.review_notice') }}</p>
    <div class="mt-4 flex flex-wrap gap-3">
        <button type="button" data-analytics-choice="grant" class="inline-flex min-h-11 items-center justify-center rounded-sm border border-line-strong bg-surface px-4 text-sm font-medium text-primary hover:bg-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('analytics.preferences.accept') }}</button>
        <button type="button" data-analytics-choice="deny" class="inline-flex min-h-11 items-center justify-center rounded-sm border border-line-strong bg-surface px-4 text-sm font-medium text-primary hover:bg-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('analytics.preferences.reject') }}</button>
        <button type="button" data-analytics-choice="withdraw" class="hidden inline-flex min-h-11 items-center justify-center rounded-sm border border-line-strong bg-surface px-4 text-sm font-medium text-primary hover:bg-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('analytics.preferences.withdraw') }}</button>
    </div>
</section>
<div class="mx-auto mb-8 w-[min(100%-2rem,72rem)] text-right">
    <button type="button" data-open-analytics-preferences aria-controls="analytics-preferences" aria-expanded="false" class="rounded-sm text-xs text-muted underline underline-offset-4 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('analytics.preferences.open') }}</button>
</div>
