@use('App\Backups\BackupText')
@use('App\Backups\BackupObjectName')
@use('App\Models\BackupObject')

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
                            @if ($backup->objects->isNotEmpty())
                                <details class="text-left">
                                    <summary class="cursor-pointer rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                                        {{ __('backups.download.package_files') }}
                                    </summary>
                                    <ul class="mt-3 space-y-3 text-left">
                                        @foreach ($backup->objects as $object)
                                            @php
                                                $objectName = BackupObjectName::tryFrom((string) $object->name);
                                                $objectExpected = $objectName === BackupObjectName::Manifest
                                                    || ($objectName !== null && in_array($objectName->value, $backup->type->objects(), true));
                                            @endphp
                                            @if ($objectExpected)
                                                <li class="rounded-md border border-line bg-surface p-3" data-backup-object="{{ $object->name }}">
                                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                                        <span class="font-medium text-primary">{{ __('backups.download.objects.'.$object->name) }}</span>
                                                        <span class="font-mono text-xs text-muted">{{ BackupText::size($object->size) }}</span>
                                                    </div>
                                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                                                        <x-ui.status-badge :tone="$object->status === BackupObject::VERIFIED ? 'positive' : 'neutral'" variant="code">
                                                            {{ __('backups.download.object_status.'.$object->status) }}
                                                        </x-ui.status-badge>
                                                        @if ($object->verified_at)
                                                            <span>{{ __('backups.download.verified_at') }} @displayDateTime($object->verified_at)</span>
                                                        @endif
                                                        @if ($object->sha256)
                                                            <details>
                                                                <summary class="cursor-pointer underline underline-offset-2">{{ __('backups.download.show_checksum') }}</summary>
                                                                <code class="mt-1 block break-all">SHA-256: {{ $object->sha256 }}</code>
                                                            </details>
                                                        @endif
                                                    </div>

                                                    @if (! $downloadsEnabled)
                                                        <p class="mt-2 text-xs text-muted">{{ __('backups.download.disabled') }}</p>
                                                    @elseif (isset($downloadableObjects[$object->id]))
                                                        <div
                                                            class="mt-3 space-y-2 text-left"
                                                            data-customer-backup-download
                                                            data-create-url="{{ route('account.websites.backups.download-grants.store', [$website, $backup, $object->name]) }}"
                                                            data-revoke-url-template="{{ route('account.backup-downloads.revoke', '__GRANT__') }}"
                                                            data-total-size="{{ $object->size }}"
                                                            data-sha256="{{ strtolower($object->sha256) }}"
                                                            data-filename="{{ $objectName->filename() }}"
                                                            data-range-limit="{{ config('backup.downloads.max_range_size', 8 * 1024 * 1024) }}"
                                                            data-disk-limit="268435456"
                                                            data-blob-limit="16777216"
                                                            data-csrf-token="{{ csrf_token() }}"
                                                        >
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <button type="button" class="inline-flex min-h-10 items-center rounded-sm bg-primary px-3 py-2 text-xs font-semibold text-white hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-60" data-download-start disabled>
                                                                    {{ __('backups.download.start') }}
                                                                </button>
                                                                <button type="button" class="inline-flex min-h-10 items-center rounded-sm border border-line px-3 py-2 text-xs font-semibold text-primary hover:bg-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" data-download-retry hidden>
                                                                    {{ __('backups.download.retry') }}
                                                                </button>
                                                                <button type="button" class="inline-flex min-h-10 items-center rounded-sm border border-line px-3 py-2 text-xs font-semibold text-muted hover:bg-subtle focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" data-download-cancel hidden>
                                                                    {{ __('backups.download.cancel') }}
                                                                </button>
                                                            </div>
                                                            <progress class="mt-2 h-2 w-full accent-action" data-download-progress max="{{ $object->size }}" value="0" aria-label="{{ __('backups.download.progress_label', ['file' => __('backups.download.objects.'.$object->name)]) }}" hidden></progress>
                                                            <p class="mt-2 text-xs text-muted" data-download-status role="status" aria-live="polite">{{ __('backups.download.messages.checking') }}</p>
                                                            <a class="mt-2 inline-flex min-h-10 items-center rounded-sm text-xs font-semibold text-primary underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" data-download-save hidden>{{ __('backups.download.save_file') }}</a>
                                                            <p class="mt-2 text-xs text-muted" data-download-limit hidden></p>
                                                            <span class="hidden" data-download-message="preparing">{{ __('backups.download.messages.preparing') }}</span>
                                                            <span class="hidden" data-download-message="smallFileOnly">{{ __('backups.download.messages.smallFileOnly') }}</span>
                                                            <span class="hidden" data-download-message="unsupported">{{ __('backups.download.messages.unsupported') }}</span>
                                                            <span class="hidden" data-download-message="browserLimit">{{ __('backups.download.messages.browserLimit') }}</span>
                                                            <span class="hidden" data-download-message="diskLimit">{{ __('backups.download.messages.diskLimit') }}</span>
                                                            <span class="hidden" data-download-message="preparationSlow">{{ __('backups.download.messages.preparationSlow') }}</span>
                                                            <span class="hidden" data-download-message="ready">{{ __('backups.download.messages.ready') }}</span>
                                                            <span class="hidden" data-download-message="downloading">{{ __('backups.download.messages.downloading') }}</span>
                                                            <span class="hidden" data-download-message="waiting">{{ __('backups.download.messages.waiting') }}</span>
                                                            <span class="hidden" data-download-message="interrupted">{{ __('backups.download.messages.interrupted') }}</span>
                                                            <span class="hidden" data-download-message="complete">{{ __('backups.download.messages.complete') }}</span>
                                                            <span class="hidden" data-download-message="cancelled">{{ __('backups.download.messages.cancelled') }}</span>
                                                            <span class="hidden" data-download-message="cancelledUnconfirmed">{{ __('backups.download.messages.cancelledUnconfirmed') }}</span>
                                                            <span class="hidden" data-download-message="cancelRevokeFailed">{{ __('backups.download.messages.cancelRevokeFailed') }}</span>
                                                            <span class="hidden" data-download-message="writerFailed">{{ __('backups.download.messages.writerFailed') }}</span>
                                                            <span class="hidden" data-download-message="writerFailedRevokeFailed">{{ __('backups.download.messages.writerFailedRevokeFailed') }}</span>
                                                            <span class="hidden" data-download-message="revokeUnconfirmed">{{ __('backups.download.messages.revokeUnconfirmed') }}</span>
                                                            <span class="hidden" data-download-message="restartAction">{{ __('backups.download.messages.restartAction') }}</span>
                                                            <span class="hidden" data-download-message="retry">{{ __('backups.download.messages.retry') }}</span>
                                                            <span class="hidden" data-download-message="expired">{{ __('backups.download.messages.expired') }}</span>
                                                            <span class="hidden" data-download-message="revoked">{{ __('backups.download.messages.revoked') }}</span>
                                                            <span class="hidden" data-download-message="failed">{{ __('backups.download.messages.failed') }}</span>
                                                            <span class="hidden" data-download-message="default-error">{{ __('backups.download.errors.default') }}</span>
                                                            @foreach (['NOT_FOUND', 'BACKUP_UNAVAILABLE', 'OBJECT_UNAVAILABLE', 'SERVICE_UNAVAILABLE', 'GRANT_EXPIRED', 'GRANT_REVOKED', 'GRANT_UNAVAILABLE', 'RANGE_INVALID', 'RANGE_TOO_LARGE', 'RANGE_CONFIGURATION_INVALID', 'OBJECT_INTEGRITY_MISMATCH', 'OBJECT_SIZE_MISMATCH', 'STORAGE_UNAVAILABLE', 'STORAGE_UNSUPPORTED', 'PREPARATION_EXPIRED', 'PREPARATION_FAILED', 'FINGERPRINT_CONFIGURATION_INVALID', 'FINGERPRINT_INVALID', 'DOWNLOAD_UNAVAILABLE', 'RATE_LIMITED', 'STREAM_INCOMPLETE', 'STREAM_FAILED', 'OBJECT_IDENTITY_CHANGED', 'REVOCATION_UNCONFIRMED', 'FILE_WRITER_FAILED'] as $errorCode)
                                                                <span class="hidden" data-download-error="{{ $errorCode }}">{{ __('backups.download.errors.'.$errorCode) }}</span>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <p class="mt-2 text-xs text-muted">{{ __('backups.download.unavailable') }}</p>
                                                    @endif
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                            @if ($backup->isRestorable() && $policy->restoreAllowed && $restoreIsolationSupported && $canBackup)
                                <a href="{{ route('account.websites.backups.restore', [$website, $backup]) }}" class="mt-2 inline-flex rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('backups.account.restore') }}</a>
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

    <x-account.panel :title="__('backups.download.guide_title')" heading-id="recovery-package-guide" class="mt-6">
        <p class="text-sm text-muted">{{ __('backups.download.guide_intro') }}</p>
        <ul class="mt-4 list-disc space-y-2 pl-5 text-sm text-primary">
            <li><strong>manifest.json</strong> — {{ __('backups.download.guide_manifest') }}</li>
            <li><strong>files.tar.gz</strong> — {{ __('backups.download.guide_files') }}</li>
            <li><strong>database.sql.gz</strong> — {{ __('backups.download.guide_database') }}</li>
        </ul>
        <p class="mt-4 text-sm text-muted">{{ __('backups.download.guide_verify') }}</p>
        <p class="mt-2 text-sm font-medium text-primary">{{ __('backups.download.not_one_click') }}</p>
        <p class="mt-2 text-xs text-muted">{{ __('backups.download.browser_limits') }}</p>
    </x-account.panel>

    @if (! $restoreIsolationSupported && $backups->isNotEmpty())
        <p class="mt-4 text-xs text-muted">{{ __('backups.account.restore_isolation_unavailable') }}</p>
    @elseif (! $policy->restoreAllowed && $backups->isNotEmpty())
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
