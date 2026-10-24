@use('App\Backups\BackupText')

<x-layouts.account :title="__('backups.account.title').' – '.$website->host()">
    <nav aria-label="Breadcrumb" class="mb-4 text-sm">
        <a href="{{ route('account.websites.show', $website) }}" class="inline-flex items-center gap-1.5 rounded-sm font-medium text-muted hover:text-primary">
            <x-ui.icon name="arrow-right" class="size-4 rotate-180" /> {{ $website->host() }}
        </a>
    </nav>

    <x-account.page-header :title="__('backups.account.title')" :lead="__('backups.account.lead')">
        @if ($canBackup && $policy->manualAllowed)
            <x-slot:actions>
                <form method="POST" action="{{ route('account.websites.backups.store', $website) }}">
                    @csrf
                    <x-ui.button type="submit" icon="archive">{{ __('backups.account.backup_now') }}</x-ui.button>
                </form>
            </x-slot:actions>
        @endif
    </x-account.page-header>

    @if ($restores->isNotEmpty())
        <x-account.panel :title="__('backups.account.restores')" heading-id="restores-title" class="mb-6">
            <ul class="divide-y divide-line text-sm">
                @foreach ($restores as $restore)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                        <span>
                            <span class="text-primary">{{ __('backups.restore.scopes.'.$restore->scope->value.'.label') }}</span>
                            <span class="font-mono text-xs text-muted">· @displayDateTime($restore->backup->created_at)</span>
                            @if ($restore->error_code)<span class="block text-xs text-danger-ink">{{ BackupText::error($restore->error_code) }}</span>@endif
                        </span>
                        <x-ui.status-badge :tone="$restore->status->tone()" variant="code">{{ $restore->status->label() }}</x-ui.status-badge>
                    </li>
                @endforeach
            </ul>
        </x-account.panel>
    @endif

    <div class="relative overflow-x-auto rounded-lg border border-line bg-surface">
        <table class="w-full min-w-[720px] text-left text-sm">
            <caption class="sr-only">{{ __('backups.account.title') }}</caption>
            <thead class="bg-subtle text-xs font-semibold tracking-[0.08em] text-muted uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('backups.account.date') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('backups.account.type') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('backups.account.reason_column') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('backups.account.size') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('backups.account.status') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('backups.account.expires') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('backups.account.actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($backups as $backup)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">@displayDateTime($backup->created_at)</td>
                        <td class="px-4 py-3 font-mono text-xs uppercase">{{ $backup->type->label() }}</td>
                        <td class="px-4 py-3">{{ $backup->reason->label() }}</td>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ BackupText::size($backup->total_size) }}</td>
                        <td class="px-4 py-3">
                            <x-ui.status-badge :tone="$backup->status->tone()" variant="code">{{ $backup->status->label() }}</x-ui.status-badge>
                            @if (in_array($backup->status->value, ['failed', 'corrupt'], true))<span class="mt-1 block text-xs text-danger-ink">{{ BackupText::error($backup->error_code) }}</span>@endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">
                            @if ($backup->status->value === 'expired'){{ app(\App\Support\DisplayDateTime::class)->date($backup->expired_at) }}@elseif ($backup->expires_at){{ app(\App\Support\DisplayDateTime::class)->date($backup->expires_at) }}@else—@endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($backup->isRestorable() && $policy->restoreAllowed && $canBackup)
                                <a href="{{ route('account.websites.backups.restore', [$website, $backup]) }}" class="rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('backups.account.restore') }}</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-muted">{{ __('backups.account.none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $backups->links() }}</div>

    @if (! $policy->restoreAllowed && $backups->isNotEmpty())
        <p class="mt-4 text-xs text-muted">{{ __('backups.account.restore_disabled') }}</p>
    @endif

    <x-account.panel :title="__('backups.account.activity')" heading-id="backup-activity-title" class="mt-6">
        @if ($events->isNotEmpty())
            <ul class="divide-y divide-line text-sm">
                @foreach ($events as $event)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                        <span class="text-primary">{{ __('backups.events.'.$event->event) }}
                            @if (! empty($event->data['code']))<span class="block text-xs text-muted">{{ BackupText::error($event->data['code']) }}</span>@endif
                        </span>
                        <span class="font-mono text-xs text-muted">@displayDateTime($event->created_at)</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-muted">{{ __('backups.account.no_activity') }}</p>
        @endif
    </x-account.panel>
</x-layouts.account>
