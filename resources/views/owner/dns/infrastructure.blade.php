<x-layouts.owner :title="__('owner.dns.infrastructure.title')">
    <h1 class="text-2xl font-semibold">{{ __('owner.dns.infrastructure.title') }}</h1>
    <p class="mt-2 text-slate-600">{{ __('owner.dns.infrastructure.lead') }}</p>
    <p class="mt-3"><a class="text-blue-700 underline" href="{{ route('owner.dns.cloudflare.index') }}">{{ __('owner.dns.back') }}</a></p>

    @if (session('status')) <p class="mt-4 rounded bg-green-50 p-3 text-green-800">{{ session('status') }}</p> @endif
    @if (session('dns_error')) <p class="mt-4 rounded bg-amber-50 p-3 text-amber-900">{{ __('owner.dns.errors.'.session('dns_error')) }}</p> @endif
    @if ($error) <p class="mt-4 rounded bg-amber-50 p-3 text-amber-900">{{ __('owner.dns.errors.'.$error) }}</p> @endif

    @php($previewId = session('dns_preview_id'))
    @if ($previewId && ($preview = session('cloudflare_dns_previews.'.$previewId)) && ($preview['kind'] ?? null) === 'infrastructure')
        <section class="mt-6 rounded border border-amber-400 bg-amber-50 p-4">
            <h2 class="font-semibold">{{ __('owner.dns.infrastructure.preview') }}</h2>
            <p>{{ __('owner.dns.preview_action') }}: <strong>{{ strtoupper($preview['plan']['action']) }}</strong></p>
            @if ($preview['plan']['before'])
                <p>{{ __('owner.dns.before') }}: {{ $preview['plan']['before']['type'] }} · {{ $preview['plan']['before']['name'] }} · {{ $preview['plan']['before']['content'] }}</p>
            @endif
            <p>{{ __('owner.dns.after') }}: {{ $preview['plan']['after']['type'] }} · {{ $preview['plan']['after']['name'] }} · {{ $preview['plan']['after']['content'] }} · {{ __('owner.dns.dns_only') }}</p>
            <form method="POST" action="{{ route('owner.dns.cloudflare.infrastructure.apply') }}" class="mt-3">@csrf
                <input type="hidden" name="preview_id" value="{{ $previewId }}">
                <label class="flex gap-2"><input type="checkbox" name="confirmed" value="1" required> {{ __('owner.dns.infrastructure.confirm') }}</label>
                <button class="mt-3 rounded bg-blue-700 px-3 py-2 text-white">{{ __('owner.dns.infrastructure.apply') }}</button>
            </form>
        </section>
    @endif

    <div class="mt-6 grid gap-4">
        @foreach ($definitions as $id => $definition)
            <section class="rounded border p-4">
                <h2 class="font-semibold">{{ __('owner.dns.infrastructure.records.'.$id) }}</h2>
                <p class="mt-1">{{ $definition['record']['type'] }} · {{ $definition['record']['name'] }} → {{ $definition['record']['content'] }} · {{ __('owner.dns.dns_only') }}</p>
                @if (isset($plans[$id]))
                    @php($statusKey = match ($plans[$id]['action']) { 'unchanged' => 'matches', 'created' => 'missing', default => 'update_needed' })
                    <p class="mt-2">{{ __('owner.dns.infrastructure.current_plan') }}: <strong>{{ __('owner.dns.infrastructure.status.'.$statusKey) }}</strong></p>
                    @if ($plans[$id]['before'])
                        <p>{{ __('owner.dns.before') }}: {{ $plans[$id]['before']['type'] }} · {{ $plans[$id]['before']['name'] }} · {{ $plans[$id]['before']['content'] }}</p>
                    @endif
                    <form method="POST" action="{{ route('owner.dns.cloudflare.infrastructure.plan') }}" class="mt-3">@csrf
                        <input type="hidden" name="definition_id" value="{{ $id }}">
                        <button class="rounded border px-3 py-2">{{ __('owner.dns.infrastructure.preview_button') }}</button>
                    </form>
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.owner>
