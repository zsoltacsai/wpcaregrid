{{-- Full administrator access: separate page, explicit confirmation, AdminAccessService decides. --}}
@use('App\Operator\Refusal')

<x-layouts.operator :title="__('operator.admin_access.confirm_title')">
    <x-operator.page-header :title="__('operator.admin_access.confirm_title')">
        <x-slot:eyebrow>
            <a href="{{ route('operator.websites.show', $website) }}#admin-access" class="underline underline-offset-2 hover:text-primary">{{ $website->host() }}</a>
        </x-slot:eyebrow>
    </x-operator.page-header>

    <x-operator.section class="max-w-2xl">
        @if ($refusal && $refusal !== 'CONFIRMATION_REQUIRED')
            <x-account.notice tone="warning">
                <strong class="font-semibold">{{ __('operator.kinds.'.Refusal::kind($refusal)) }}:</strong> {{ Refusal::message($refusal) }}
                <span class="ml-1 font-mono text-[11px]">{{ $refusal }}</span>
            </x-account.notice>
        @else
            <p class="text-sm text-primary">{{ __('operator.admin_access.confirm_text', ['host' => $website->host(), 'minutes' => $minutes]) }}</p>
            <form method="POST" action="{{ route('operator.websites.admin-access.full-admin.store', $website) }}" target="_blank" class="mt-5 grid gap-4">
                @csrf
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="confirm" value="1" required class="mt-0.5 size-4 accent-[var(--color-action)]">
                    <span>{{ __('operator.admin_access.confirm_label') }}</span>
                </label>
                <div class="flex flex-wrap gap-3">
                    <x-ui.button type="submit" variant="danger" icon="shield">{{ __('operator.admin_access.confirm_submit') }}</x-ui.button>
                    <x-ui.button :href="route('operator.websites.show', $website)" variant="secondary">{{ __('operator.admin_access.cancel') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-operator.section>
</x-layouts.operator>
