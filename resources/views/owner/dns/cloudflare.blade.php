<x-layouts.owner :title="__('owner.dns.title')">
    <h1 class="text-2xl font-semibold">{{ __('owner.dns.title') }}</h1>
    <p class="mt-2 text-slate-600">{{ __('owner.dns.lead') }}</p>
    <p class="mt-3"><a class="text-blue-700 underline" href="{{ route('owner.dns.cloudflare.infrastructure') }}">{{ __('owner.dns.infrastructure.link') }}</a></p>
    @if (session('status')) <p class="mt-4 rounded bg-green-50 p-3 text-green-800">{{ session('status') }}</p> @endif
    @if ($error) <p class="mt-4 rounded bg-amber-50 p-3 text-amber-900">{{ __('owner.dns.errors.'.$error) }}</p> @endif
    <section class="mt-6 rounded border p-4">
        <h2 class="font-semibold">{{ __('owner.dns.connection') }}</h2>
        @if ($configured['owner_managed'])
            <p>{{ __('owner.dns.owner_token_configured') }}</p>
            @if ($configured['environment_fallback']) <p>{{ __('owner.dns.environment_fallback_overridden') }}</p> @endif
        @elseif ($configured['environment_fallback'])
            <p>{{ __('owner.dns.environment_fallback_active') }}</p>
        @else
            <p>{{ __('owner.integrations.not_configured') }}</p>
        @endif
        @if ($diagnostic)
            <p>{{ __('owner.dns.api_reachable') }} · {{ __('owner.dns.dns_read_available') }} · {{ __('owner.dns.dns_edit_unknown') }}</p>
        @endif
    </section>
    <section class="mt-6">
        <h2 class="font-semibold">{{ __('owner.dns.approved_zones') }}</h2>
        <ul class="mt-2 list-disc pl-6">
            @forelse ($zones as $zone)
                <li><a class="text-blue-700 underline" href="{{ route('owner.dns.cloudflare.zone', ['zone' => $zone]) }}">{{ $zone }}</a></li>
            @empty
                <li>{{ __('owner.dns.no_approved_zones') }}</li>
            @endforelse
        </ul>
    </section>
</x-layouts.owner>
