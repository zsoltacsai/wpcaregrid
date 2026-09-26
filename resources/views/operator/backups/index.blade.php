@use('App\Backups\BackupReason')
@use('App\Backups\BackupStatus')
@use('App\Backups\BackupText')

<x-layouts.operator :title="__('operator.backups.title')">
    <x-operator.page-header :title="__('operator.backups.title')" :lead="__('operator.backups.lead')" />

    @php $select = 'h-9 rounded-sm border border-line-strong bg-surface px-2 text-sm text-ink hover:border-muted'; @endphp

    <form method="GET" action="{{ route('operator.backups.index') }}" class="flex flex-wrap items-end gap-3 rounded-md border border-line bg-surface p-3">
        <div>
            <label for="status" class="block text-xs font-medium text-muted">{{ __('operator.backups.status') }}</label>
            <select id="status" name="status" class="{{ $select }} mt-1">
                <option value="">{{ __('operator.backups.all_statuses') }}</option>
                @foreach (BackupStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="reason" class="block text-xs font-medium text-muted">{{ __('operator.backups.reason') }}</label>
            <select id="reason" name="reason" class="{{ $select }} mt-1">
                <option value="">{{ __('operator.backups.all_reasons') }}</option>
                @foreach (BackupReason::cases() as $case)
                    <option value="{{ $case->value }}" @selected($reason === $case)>{{ $case->label() }}</option>
                @endforeach
            </select>
        </div>
        <x-ui.button type="submit" size="sm" class="h-9">{{ __('operator.backups.filter') }}</x-ui.button>
    </form>

    @if ($backups->isEmpty())
        <p class="mt-4 rounded-md border border-line bg-surface p-6 text-sm text-muted">{{ __('operator.backups.none') }}</p>
    @else
        <div class="mt-4 relative overflow-x-auto rounded-md border border-line bg-surface">
            <table class="w-full min-w-[820px] text-left text-sm">
                <thead class="bg-canvas text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">
                    <tr>
                        <th scope="col" class="px-3 py-2">{{ __('operator.backups.created') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.backups.website') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.backups.type') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.backups.reason') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.backups.status') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.backups.size') }}</th>
                        <th scope="col" class="px-3 py-2">{{ __('operator.backups.expires') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($backups as $backup)
                        <tr class="align-top">
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ $backup->created_at->format('Y-m-d H:i') }}</td>
                            <td class="px-3 py-2"><a href="{{ route('operator.websites.show', $backup->website) }}#backups" class="text-primary hover:underline">{{ $backup->website->host() }}</a></td>
                            <td class="px-3 py-2 text-xs">{{ $backup->type->label() }}</td>
                            <td class="px-3 py-2 text-xs">{{ $backup->reason->label() }}</td>
                            <td class="px-3 py-2">
                                <x-ui.status-badge :tone="$backup->status->tone()" variant="code">{{ $backup->status->label() }}</x-ui.status-badge>
                                @if ($backup->error_code)<span class="mt-1 block font-mono text-[11px] text-danger-ink">{{ $backup->error_code }}</span>@endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">{{ BackupText::size($backup->total_size) }}</td>
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">{{ $backup->expires_at?->format('Y-m-d') ?? __('operator.none') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $backups->links() }}</div>
    @endif
</x-layouts.operator>
