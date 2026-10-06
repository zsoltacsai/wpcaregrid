<x-layouts.owner :title="__('owner.dns.zone_title', ['zone' => $zone])">
    <h1 class="text-2xl font-semibold">{{ __('owner.dns.zone_title', ['zone' => $zone]) }}</h1>
    <p class="mt-2 text-slate-600">{{ __('owner.dns.zone_lead') }}</p>
    <p class="mt-3"><a class="text-blue-700 underline" href="{{ route('owner.dns.cloudflare.index') }}">{{ __('owner.dns.back') }}</a></p>
    <p class="mt-2"><a class="text-blue-700 underline" href="{{ request()->url() }}">{{ __('owner.dns.refresh') }}</a></p>
    @if (session('status')) <p class="mt-4 rounded bg-green-50 p-3 text-green-800">{{ session('status') }}</p> @endif
    @if (session('dns_error')) <p class="mt-4 rounded bg-amber-50 p-3 text-amber-900">{{ __('owner.dns.errors.'.session('dns_error')) }}</p> @endif
    @if ($error) <p class="mt-4 rounded bg-amber-50 p-3 text-amber-900">{{ __('owner.dns.errors.'.$error) }}</p> @endif

    @php($previewId = session('dns_preview_id'))
    @if ($previewId && ($preview = session('cloudflare_dns_previews.'.$previewId)))
        <section class="mt-6 rounded border border-amber-400 bg-amber-50 p-4">
            <h2 class="font-semibold">{{ __('owner.dns.preview') }}</h2>
            <p>{{ __('owner.dns.preview_action') }}: <strong>{{ strtoupper($preview['plan']['action']) }}</strong></p>
            @if (($preview['kind'] ?? null) === 'delete')
                <p>{{ $preview['plan']['before']['type'] }} · {{ $preview['plan']['before']['name'] }} · {{ $preview['plan']['before']['content'] }}</p>
                <form method="POST" action="{{ route('owner.dns.cloudflare.records.delete.apply') }}" class="mt-3">@csrf
                    <input type="hidden" name="preview_id" value="{{ $previewId }}">
                    <label class="flex gap-2"><input type="checkbox" name="confirmed" value="1" required> {{ __('owner.dns.confirm_delete') }}</label>
                    <button class="mt-3 rounded bg-red-700 px-3 py-2 text-white">{{ __('owner.dns.apply_delete') }}</button>
                </form>
            @else
                @if ($preview['plan']['before'])
                    <p>{{ __('owner.dns.before') }}: {{ $preview['plan']['before']['type'] }} · {{ $preview['plan']['before']['name'] }} · {{ $preview['plan']['before']['content'] ?? '' }} · TTL {{ $preview['plan']['before']['ttl'] ?? '' }} · {{ ($preview['plan']['before']['proxied'] ?? false) ? __('owner.dns.proxied') : __('owner.dns.dns_only') }} @if(isset($preview['plan']['before']['priority'])) · MX priority {{ $preview['plan']['before']['priority'] }} @endif</p>
                @endif
                <p>{{ __('owner.dns.after') }}: {{ $preview['plan']['after']['type'] }} · {{ $preview['plan']['after']['name'] }} · {{ $preview['plan']['after']['content'] }} · TTL {{ $preview['plan']['after']['ttl'] }} · {{ $preview['plan']['after']['proxied'] ? __('owner.dns.proxied') : __('owner.dns.dns_only') }} @if(isset($preview['plan']['after']['priority'])) · MX priority {{ $preview['plan']['after']['priority'] }} @endif</p>
                <form method="POST" action="{{ route('owner.dns.cloudflare.records.apply') }}" class="mt-3">@csrf
                    <input type="hidden" name="preview_id" value="{{ $previewId }}">
                    <label class="flex gap-2"><input type="checkbox" name="confirmed" value="1" required> {{ __('owner.dns.confirm_apply') }}</label>
                    <button class="mt-3 rounded bg-blue-700 px-3 py-2 text-white">{{ __('owner.dns.apply') }}</button>
                </form>
            @endif
        </section>
    @endif

    @if (! $error)
        <div class="mt-6 overflow-x-auto">
            <table class="min-w-full text-left"><thead><tr><th>{{ __('owner.dns.type') }}</th><th>{{ __('owner.dns.name') }}</th><th>{{ __('owner.dns.content') }}</th><th>{{ __('owner.dns.ttl') }}</th><th>{{ __('owner.dns.proxy') }}</th><th>{{ __('owner.dns.priority') }}</th><th>{{ __('owner.dns.actions') }}</th></tr></thead>
                <tbody>@forelse ($records as $record)
                    <tr class="border-t"><td>{{ $record['type'] ?? '' }}</td><td>{{ $record['name'] ?? '' }}</td><td class="max-w-sm break-all">{{ $record['content'] ?? '' }}</td><td>{{ ($record['ttl'] ?? 1) === 1 ? __('owner.dns.auto') : ($record['ttl'] ?? '') }}</td><td>{{ ($record['proxied'] ?? false) ? __('owner.dns.proxied') : __('owner.dns.dns_only') }}</td><td>{{ $record['priority'] ?? '' }}</td><td>
                        <details><summary class="cursor-pointer text-blue-700 underline">{{ __('owner.dns.edit') }}</summary>
                            <form method="POST" action="{{ route('owner.dns.cloudflare.records.plan') }}" class="grid gap-2">@csrf
                                <input type="hidden" name="zone" value="{{ $zone }}"><input type="hidden" name="type" value="{{ $record['type'] ?? '' }}">
                                <input type="hidden" name="record_id" value="{{ $record['id'] ?? '' }}">
                                <label>{{ __('owner.dns.name') }}<input name="name" value="{{ $record['name'] ?? '' }}" required class="block rounded border"></label>
                                <label>{{ __('owner.dns.content') }}<input name="content" value="{{ $record['content'] ?? '' }}" required class="block rounded border"></label>
                                <label>{{ __('owner.dns.ttl') }}<input name="ttl" value="{{ $record['ttl'] ?? 1 }}" class="block rounded border"></label>
                                @if (isset($record['priority']))<label>{{ __('owner.dns.priority') }}<input type="number" name="priority" value="{{ $record['priority'] }}" min="0" max="65535" class="block rounded border"></label>@endif
                                <label class="flex gap-2"><input type="checkbox" name="proxied" value="1" @checked($record['proxied'] ?? false)> {{ __('owner.dns.proxied') }}</label>
                                @if (isset($record['comment']))<input type="hidden" name="comment" value="{{ $record['comment'] }}">@endif
                                <button class="text-blue-700 underline">{{ __('owner.dns.preview') }}</button>
                            </form>
                        </details>
                        @if (in_array(strtoupper($record['type'] ?? ''), ['A', 'AAAA', 'CNAME', 'TXT', 'MX'], true))
                            <form method="POST" action="{{ route('owner.dns.cloudflare.records.delete.plan') }}">@csrf
                                <input type="hidden" name="zone" value="{{ $zone }}"><input type="hidden" name="record_id" value="{{ $record['id'] ?? '' }}">
                                <button class="text-red-700 underline">{{ __('owner.dns.delete') }}</button>
                            </form>
                        @endif
                    </td></tr>
                @empty <tr><td colspan="7" class="py-3">{{ __('owner.dns.empty') }}</td></tr> @endforelse</tbody>
            </table>
        </div>
        <section class="mt-8 rounded border p-4">
            <h2 class="font-semibold">{{ __('owner.dns.ensure_title') }}</h2>
            <p>{{ __('owner.dns.ensure_lead') }}</p>
            <form method="POST" action="{{ route('owner.dns.cloudflare.records.plan') }}" class="mt-4 grid gap-3 md:grid-cols-2">@csrf
                <input type="hidden" name="zone" value="{{ $zone }}">
                <label>{{ __('owner.dns.type') }}<select name="type" required class="block w-full rounded border"><option>A</option><option>AAAA</option><option>CNAME</option><option>TXT</option><option>MX</option></select></label>
                <label>{{ __('owner.dns.name') }}<input name="name" required maxlength="253" class="block w-full rounded border"></label>
                <label class="md:col-span-2">{{ __('owner.dns.content') }}<input name="content" required maxlength="4096" class="block w-full rounded border"></label>
                <label>{{ __('owner.dns.ttl') }}<select name="ttl" class="block w-full rounded border"><option value="auto">{{ __('owner.dns.auto') }}</option><option>300</option><option>600</option><option>3600</option></select></label>
                <label>{{ __('owner.dns.priority') }}<input name="priority" type="number" min="0" max="65535" class="block w-full rounded border"></label>
                <label class="flex gap-2"><input type="checkbox" name="proxied" value="1"> {{ __('owner.dns.proxied') }}</label>
                <button class="rounded bg-blue-700 px-3 py-2 text-white">{{ __('owner.dns.preview') }}</button>
            </form>
        </section>
    @endif
</x-layouts.owner>
