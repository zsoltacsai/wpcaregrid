{{--
    One audit log entry as a table row: time, website, actor, event, result.
    Shows codes and identifiers only; the log never holds secrets.
--}}
@props(['event', 'showWebsite' => true])

@php
    $key = 'operator.events.'.$event->event;
    $label = __($key) !== $key ? __($key) : $event->event;
    $result = match (true) {
        str_ends_with($event->event, '.failed'), str_ends_with($event->event, '.corrupt'), str_ends_with($event->event, '.blocked'), $event->event === 'license.expired' => 'failed',
        str_ends_with($event->event, '.verified'), str_ends_with($event->event, '.completed'), str_ends_with($event->event, '.started'), str_ends_with($event->event, '.paired') => 'ok',
        default => 'info',
    };
    $tone = ['failed' => 'danger', 'ok' => 'positive', 'info' => 'neutral'][$result];
    $type = $event->actor_type ?? 'system';
    $detail = $event->data['code'] ?? $event->data['result'] ?? $event->data['reason'] ?? null;
    $target = $event->data['target'] ?? $event->data['plugin'] ?? null;
@endphp

<tr class="align-top">
    <td class="px-3 py-2 font-mono text-xs whitespace-nowrap text-muted">@displayDateTime($event->created_at)</td>
    @if ($showWebsite)
        <td class="px-3 py-2 text-sm">
            @if ($event->website)
                <a href="{{ route('operator.websites.show', $event->website) }}" class="text-primary underline-offset-2 hover:underline">{{ $event->website->host() }}</a>
            @endif
        </td>
    @endif
    <td class="px-3 py-2 text-xs whitespace-nowrap">
        <span class="font-medium text-primary">{{ __('operator.actors.'.$type) }}</span>
        @if ($event->actorUser)
            <span class="block max-w-[180px] truncate text-muted">{{ $event->actorUser->email }}</span>
        @endif
    </td>
    <td class="px-3 py-2 text-sm text-primary">
        {{ $label }}
        @if ($target)<span class="block font-mono text-[11px] break-all text-muted">{{ $target }}</span>@endif
    </td>
    <td class="px-3 py-2 whitespace-nowrap">
        <x-ui.status-badge :tone="$tone" variant="code">{{ __('operator.results.'.$result) }}</x-ui.status-badge>
        @if ($detail)<span class="mt-1 block font-mono text-[11px] text-muted">{{ $detail }}</span>@endif
    </td>
</tr>
