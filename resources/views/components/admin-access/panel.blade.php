{{--
    WordPress admin access on the website page: current state, one action
    per access level, revoke. Never shows tokens or session data.
--}}
@use('App\AdminAccess\AccessLevel')
@use('App\AdminAccess\AdminAccessService')
@props(['website', 'canOpen'])

@php
    $service = app(AdminAccessService::class);
    $open = $website->adminAccessRequests()->whereIn('status', ['pending', 'authorized', 'active'])->latest('id')->get();
    $live = $open->filter(fn ($request) => $request->status->value === 'active' && $request->session_expires_at?->isFuture());
    $waiting = $open->filter(fn ($request) => $request->status->value !== 'active');
@endphp

<x-account.panel :title="__('admin_access.account.panel')" heading-id="admin-access-title" {{ $attributes }}>
    <p class="text-sm text-muted">{{ __('admin_access.account.lead') }}</p>

    <div class="mt-5">
        @forelse ($live as $request)
            @php $minutes = (int) floor(now()->diffInMinutes($request->session_expires_at)); @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line px-4 py-3">
                <span class="flex flex-wrap items-center gap-3 text-sm">
                    <x-ui.status-badge tone="warning">{{ __('admin_access.account.active') }}</x-ui.status-badge>
                    <span class="font-medium text-primary">{{ $request->access_level->label() }}</span>
                    <span class="font-mono text-xs text-muted">{{ $minutes >= 1 ? __('admin_access.account.expires_in', ['minutes' => $minutes]) : __('admin_access.account.expires_soon') }}</span>
                </span>
                <form method="POST" action="{{ route('account.websites.admin-access.revoke', [$website, $request]) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm">{{ __('admin_access.account.revoke') }}</x-ui.button>
                </form>
            </div>
        @empty
            @if ($waiting->isEmpty())
                <p class="flex items-center gap-2 text-sm text-ink"><x-ui.status-badge tone="neutral">{{ __('admin_access.account.none') }}</x-ui.status-badge></p>
            @endif
        @endforelse
        @foreach ($waiting as $request)
            <p class="mt-2 text-xs text-muted">{{ $request->access_level->label() }}: {{ __('admin_access.account.opening') }}</p>
        @endforeach
    </div>

    @if ($canOpen)
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="flex min-w-0 flex-col rounded-md border border-line p-4">
                <p class="text-sm font-semibold text-primary">{{ __('admin_access.account.maintenance_title') }}</p>
                <p class="mt-1 flex-1 text-sm text-muted">{{ __('admin_access.account.maintenance_text', ['minutes' => AccessLevel::Maintenance->minutes()]) }}</p>
                <form method="POST" action="{{ route('account.websites.admin-access.maintenance', $website) }}" target="_blank" class="mt-4">
                    @csrf
                    {{-- Long labels wrap on the narrowest phones instead of widening the page. --}}
                    <x-ui.button type="submit" icon="arrow-right" class="h-auto! min-h-10 max-w-full py-2 text-left whitespace-normal!">{{ __('admin_access.account.open') }}</x-ui.button>
                </form>
            </div>
            <div class="flex min-w-0 flex-col rounded-md border border-line p-4">
                <p class="text-sm font-semibold text-primary">{{ __('admin_access.account.full_title') }}</p>
                <p class="mt-1 flex-1 text-sm text-muted">{{ __('admin_access.account.full_text', ['minutes' => AccessLevel::FullAdmin->minutes()]) }}</p>
                <div class="mt-4">
                    <x-ui.button :href="route('account.websites.admin-access.full-admin', $website)" variant="secondary" icon="shield" class="h-auto! min-h-10 max-w-full py-2 text-left whitespace-normal!">{{ __('admin_access.account.enable_full') }}</x-ui.button>
                </div>
            </div>
        </div>
    @endif

    <p class="mt-4 flex items-start gap-2 text-xs text-muted"><x-ui.icon name="lock" class="size-4 text-accent-ink" />{{ __('admin_access.account.no_password') }}</p>
</x-account.panel>
