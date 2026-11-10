<p>{{ __('platform_updates.mail.intro.'.$kind) }}</p>
@if ($items !== [])
    <ul>
        @foreach ($items as $item)
            <li>
                <strong>{{ $item['component'] }} {{ $item['installed'] }}</strong> — {{ $item['advisory'] }}@if ($item['aliases'] !== '') ({{ $item['aliases'] }})@endif,
                {{ __('platform_updates.severity.'.$item['severity']) }}@if ($item['fixed'] !== null), {{ __('platform_updates.mail.fixed_in', ['version' => $item['fixed']]) }}@endif
                — {{ $item['source'] }}
            </li>
        @endforeach
    </ul>
@endif
@if ($summary !== [])
    <p>{{ __('platform_updates.mail.summary', $summary) }}</p>
@endif
<p>{{ __('platform_updates.mail.no_automatic') }}</p>
<p><a href="{{ $url }}">{{ __('platform_updates.mail.open_page') }}</a></p>
