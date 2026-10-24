{{--
    Backups panel on the website page: the latest verified backup (the only
    kind presented as a success), anything in progress or failed since, and
    the actions.
--}}
@use('App\Backups\BackupText')
@props(['website', 'latest', 'verified', 'policy', 'canBackup'])

<x-account.panel :title="__('backups.account.panel')" heading-id="backups-title" {{ $attributes }}>
    <x-slot:action>
        <a href="{{ route('account.websites.backups.index', $website) }}" class="rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('backups.account.view_all') }}</a>
    </x-slot:action>

    @if ($latest && $latest->status->inProgress())
        <x-account.notice class="mb-5">{{ __('backups.account.in_progress') }} <span class="font-mono text-xs">{{ $latest->status->label() }}</span></x-account.notice>
    @elseif ($latest && in_array($latest->status->value, ['failed', 'corrupt'], true))
        <div class="mb-5 rounded-md border border-danger/35 bg-danger-soft px-4 py-3 text-sm text-danger-ink" role="alert">
            <p class="font-semibold">{{ __('backups.account.failed_title') }}</p>
            <p class="mt-1">{{ __('backups.account.reason') }}: {{ BackupText::error($latest->error_code) }}</p>
            <p class="mt-1">{{ __('backups.account.retry') }}: {{ __('backups.account.retry_available') }}</p>
        </div>
    @endif

    @if ($verified)
        <dl class="grid gap-5 text-sm sm:grid-cols-3">
            <x-account.detail :label="__('backups.account.latest')">
                <x-ui.status-badge tone="positive">{{ __('backups.statuses.verified') }}</x-ui.status-badge>
            </x-account.detail>
            <x-account.detail :label="__('backups.account.type')">{{ $verified->type->label() }}</x-account.detail>
            <x-account.detail :label="__('backups.account.created')"><span class="font-mono">@displayDateTime($verified->created_at)</span></x-account.detail>
            <x-account.detail :label="__('backups.account.size')"><span class="font-mono">{{ BackupText::size($verified->total_size) }}</span></x-account.detail>
            <x-account.detail :label="__('backups.account.expires')">
                @if ($verified->expires_at)<span class="font-mono">{{ app(\App\Support\DisplayDateTime::class)->date($verified->expires_at) }}</span>@elseif ($policy->retentionCount){{ __('backups.account.expires_by_count') }}@endif
            </x-account.detail>
            <x-account.detail :label="__('backups.account.frequency')">{{ $policy->frequency ? __('backups.account.frequencies.'.$policy->frequency) : '' }}</x-account.detail>
        </dl>
    @else
        <p class="text-sm text-muted">{{ $latest ? __('backups.account.none_verified') : __('backups.account.none') }}</p>
    @endif

    @if ($canBackup && $policy->manualAllowed)
        <form method="POST" action="{{ route('account.websites.backups.store', $website) }}" class="mt-6">
            @csrf
            <x-ui.button type="submit" variant="secondary" icon="archive">{{ __('backups.account.backup_now') }}</x-ui.button>
        </form>
    @endif
</x-account.panel>
