<x-layouts.marketing locale-form="forgot-form" :title="__('account.meta_title', ['page' => __('account.auth.forgot_title')])" :description="__('home.meta.description')">
    <x-auth.card :title="__('account.auth.forgot_title')" :lead="__('account.auth.forgot_lead')">
        <form id="forgot-form" method="POST" action="{{ route('password.email') }}" class="grid gap-5" novalidate>
            @csrf
            <x-form.field name="email" type="email" :label="__('account.auth.email')" autocomplete="email" required autofocus />
            <x-ui.button type="submit" size="lg" class="w-full">{{ __('account.auth.send_link') }}</x-ui.button>
        </form>

        <p class="mt-6 text-sm"><a href="{{ route('login') }}" class="rounded-sm font-medium text-primary underline decoration-line-strong underline-offset-4 hover:decoration-primary">{{ __('account.auth.back_to_login') }}</a></p>
    </x-auth.card>
</x-layouts.marketing>
