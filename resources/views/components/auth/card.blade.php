{{-- Centred card for login, password and activation pages. --}}
@props(['title', 'lead' => null])

<section class="py-16 sm:py-20" aria-labelledby="auth-title">
    <x-ui.container class="max-w-[480px]">
        <div class="rounded-lg border border-line bg-surface p-6 sm:p-8">
            <h1 id="auth-title" class="text-[26px] leading-[1.15] font-semibold tracking-[-0.015em]">{{ $title }}</h1>
            @if ($lead)
                <p class="mt-2 text-sm text-muted">{{ $lead }}</p>
            @endif

            @if (session('status'))
                <x-account.notice tone="positive" class="mt-6">{{ session('status') }}</x-account.notice>
            @endif

            <div class="mt-6">{{ $slot }}</div>
        </div>
    </x-ui.container>
</section>
