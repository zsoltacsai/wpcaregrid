<x-layouts.account locale-form="website-form" :title="__('account.onboarding.title')">
    <x-account.page-header :title="__('account.onboarding.title')" :lead="__('account.onboarding.lead')" />

    <x-account.onboarding-steps :states="['connect' => 'current', 'connection' => 'not_started', 'initial_check' => 'not_started', 'ready' => 'not_started']" />

    <x-account.panel :title="__('account.onboarding.steps.connect.title')" heading-id="connect-title" class="mt-6">
        @if ($errors->any())<x-account.notice tone="warning" class="mb-5">{{ $errors->first() }}</x-account.notice>@endif
        <form id="website-form" method="POST" action="{{ route('account.websites.store') }}" class="grid max-w-xl gap-5" novalidate>
            @csrf
            <x-form.field name="url" type="url" :label="__('account.onboarding.url')" :hint="__('account.onboarding.url_hint')" placeholder="https://company.example" autocomplete="url" required maxlength="255" />

            <fieldset>
                <legend class="text-sm font-medium text-primary">{{ __('account.onboarding.type') }}</legend>
                <div class="mt-2 grid gap-3 sm:grid-cols-2">
                    @foreach (\App\Pricing\ProductType::cases() as $type)
                        <label class="flex cursor-pointer items-center gap-3 rounded-md border border-line-strong bg-surface p-4 text-sm font-medium text-primary hover:border-muted has-checked:border-accent has-checked:bg-accent-soft has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent-ink">
                            <input type="radio" name="type" value="{{ $type->value }}" @checked(old('type', $defaultType->value) === $type->value) class="size-4 accent-action">
                            {{ $type->label() }}
                        </label>
                    @endforeach
                </div>
                @error('type')<p class="mt-2 text-sm text-danger-ink">{{ $message }}</p>@enderror
            </fieldset>

            <x-ui.button type="submit" size="lg" icon="arrow-right" class="self-start">{{ __('account.websites.continue_to_options') }}</x-ui.button>
        </form>
    </x-account.panel>
</x-layouts.account>
