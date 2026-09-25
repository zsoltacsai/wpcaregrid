<x-layouts.account :title="__('admin_access.confirm.title')">
    <nav aria-label="Breadcrumb" class="mb-4 text-sm">
        <a href="{{ route('account.websites.show', $website) }}" class="inline-flex items-center gap-1.5 rounded-sm font-medium text-muted hover:text-primary">
            <x-ui.icon name="arrow-right" class="size-4 rotate-180" /> {{ $website->host() }}
        </a>
    </nav>

    <x-account.page-header :title="__('admin_access.confirm.title')" />

    <x-account.panel class="max-w-2xl">
        @if ($refusal && $refusal !== 'CONFIRMATION_REQUIRED')
            @php $key = 'admin_access.refusals.'.$refusal; @endphp
            <x-account.notice tone="warning"><strong class="font-semibold">{{ __('admin_access.confirm.not_possible') }}.</strong> {{ __($key) !== $key ? __($key) : __('admin_access.refusals.default') }}</x-account.notice>
        @else
            <h2 class="text-lg font-semibold text-primary">{{ __('admin_access.confirm.question', ['minutes' => $minutes]) }}</h2>
            <x-account.notice tone="warning" class="mt-4">{{ __('admin_access.confirm.warning') }}</x-account.notice>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach (__('admin_access.confirm.points') as $point)
                    <li class="flex gap-3"><x-ui.icon name="check" class="mt-0.5 size-4 text-accent-ink" /><span>{{ str_replace(':minutes', $minutes, $point) }}</span></li>
                @endforeach
            </ul>

            <form method="POST" action="{{ route('account.websites.admin-access.full-admin.store', $website) }}" target="_blank" class="mt-6 grid gap-5">
                @csrf
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 accent-[var(--color-action)]">
                    <span>{{ __('admin_access.confirm.checkbox', ['host' => $website->host()]) }}</span>
                </label>
                @error('confirm')<p class="-mt-3 text-sm text-danger-ink">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-3">
                    <x-ui.button type="submit" variant="danger" icon="shield">{{ __('admin_access.confirm.submit', ['minutes' => $minutes]) }}</x-ui.button>
                    <x-ui.button :href="route('account.websites.show', $website)" variant="secondary">{{ __('admin_access.confirm.cancel') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-account.panel>
</x-layouts.account>
