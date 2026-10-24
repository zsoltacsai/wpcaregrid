{{-- Website audit log entries (website_events) for the owner: codes and identifiers only. --}}
@props(['events', 'showWebsite' => true])

@if ($events->isEmpty())
    <p class="text-sm text-muted">{{ __('owner.audit.empty') }}</p>
@else
    <ul class="divide-y divide-line text-sm">
        @foreach ($events as $event)
            @php
                $key = 'operator.events.'.$event->event;
                $detail = $event->data['code'] ?? $event->data['result'] ?? $event->data['reason'] ?? null;
            @endphp
            <li class="flex flex-col gap-0.5 py-2 sm:flex-row sm:items-baseline sm:gap-3">
                <span class="shrink-0 font-mono text-xs text-muted">@displayDateTime($event->created_at)</span>
                <span class="min-w-0 flex-1 text-primary">
                    {{ __($key) !== $key ? __($key) : $event->event }}
                    @if ($detail)<span class="font-mono text-[11px] text-muted">{{ $detail }}</span>@endif
                    @if ($showWebsite && $event->website)
                        <a href="{{ route('owner.websites.show', $event->website) }}" class="block text-xs break-all text-muted hover:underline">{{ $event->website->host() }}</a>
                    @endif
                </span>
                <span class="shrink-0 text-xs text-muted">{{ __('operator.actors.'.($event->actor_type ?? 'system')) }}</span>
            </li>
        @endforeach
    </ul>
@endif
