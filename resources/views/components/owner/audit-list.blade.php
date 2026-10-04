{{-- Owner audit entries: when, who, what, which item. Codes and field names only. --}}
@props(['events'])

@if ($events->isEmpty())
    <p class="text-sm text-muted">{{ __('owner.audit.empty') }}</p>
@else
    <ul class="divide-y divide-line text-sm">
        @foreach ($events as $event)
            @php
                $key = 'owner.audit.events.'.$event->event;
                $fields = $event->data['fields'] ?? null;
            @endphp
            <li class="flex flex-col gap-0.5 py-2 sm:flex-row sm:items-baseline sm:gap-3">
                <span class="shrink-0 font-mono text-xs text-muted">{{ $event->created_at->format('Y-m-d H:i') }}</span>
                <span class="min-w-0 flex-1 text-primary">
                    {{ __($key) !== $key ? __($key) : $event->event }}
                    <span class="font-mono text-[11px] break-all text-muted">{{ $event->subject_key }}</span>
                    @if (is_array($fields) && $fields !== [])
                        <span class="block text-xs text-muted">{{ __('owner.audit.fields', ['fields' => implode(', ', $fields)]) }}</span>
                    @endif
                </span>
                <span class="shrink-0 truncate text-xs text-muted">{{ $event->actor?->email ?? __('owner.audit.system') }}</span>
            </li>
        @endforeach
    </ul>
@endif
