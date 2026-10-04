<x-layouts.marketing locale-form="reset-form" :title="__('account.meta_title', ['page' => __('account.auth.reset_title')])" :description="__('home.meta.description')">
    <x-auth.card :title="__('account.auth.reset_title')" >
        <form id="reset-form" method="POST" action="{{ route('password.update') }}" class="grid gap-5" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <x-form.field name="email" type="email" :label="__('account.auth.email')" :value="$email" autocomplete="email" required :readonly="filled($email)" />
            <x-form.field name="password" type="password" :label="__('account.auth.password')" :hint="__('account.auth.password_rules')" autocomplete="new-password" required minlength="12" />
            <x-form.field name="password_confirmation" type="password" :label="__('account.auth.password_confirmation')" autocomplete="new-password" required minlength="12" />
            <x-ui.button type="submit" size="lg" class="w-full">{{ __('account.auth.reset') }}</x-ui.button>
        </form>
    </x-auth.card>
</x-layouts.marketing>
