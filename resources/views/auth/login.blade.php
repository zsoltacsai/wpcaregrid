<x-layouts.marketing locale-form="login-form" :title="__('account.meta_title', ['page' => __('account.auth.login_title')])" :description="__('home.meta.description')">
    <x-auth.card :title="__('account.auth.login_title')" :lead="__('account.auth.login_lead')">
        <form id="login-form" method="POST" action="{{ route('login') }}" class="grid gap-5" novalidate>
            @csrf
            <x-form.field name="email" type="email" :label="__('account.auth.email')" autocomplete="email" required autofocus />
            <x-form.field name="password" type="password" :label="__('account.auth.password')" autocomplete="current-password" required />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <label class="flex items-center gap-2 text-sm text-ink">
                    <input type="checkbox" name="remember" value="1" class="size-4 rounded-sm border-line-strong accent-action">
                    {{ __('account.auth.remember') }}
                </label>
                <a href="{{ route('password.request') }}" class="rounded-sm text-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('account.auth.forgot') }}</a>
            </div>

            <x-ui.button type="submit" size="lg" class="w-full">{{ __('account.auth.login') }}</x-ui.button>
        </form>

        <p class="mt-6 border-t border-line pt-6 text-sm text-muted">{{ __('account.auth.no_account') }}</p>
    </x-auth.card>
</x-layouts.marketing>
