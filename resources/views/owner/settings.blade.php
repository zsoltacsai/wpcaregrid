<x-layouts.owner :title="__('owner.settings.groups.'.$section.'.title')">
    <x-operator.page-header :title="__('owner.settings.title')" :lead="__('owner.settings.lead')" />

    @if (session('status'))
        <p class="mb-4 rounded-md border border-success/30 bg-success-soft p-3 text-sm text-success-ink" role="status">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="mb-4 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm text-danger-ink" role="alert">{{ session('error') }}</p>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-md border border-danger/30 bg-danger-soft p-3 text-sm text-danger-ink" role="alert">
            <p>{{ __('owner.settings.validation.title') }}</p>
            <ul class="mt-1 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
        <nav aria-label="{{ __('owner.settings.navigation') }}" class="h-fit rounded-md border border-line bg-surface p-2">
            <ul class="space-y-1">
                @foreach ($sections as $item)
                    <li>
                        <a href="{{ route('owner.settings.index', ['section' => $item]) }}" @if ($section === $item) aria-current="page" @endif
                           @class(['block rounded-sm px-3 py-2 text-sm', 'bg-action text-white' => $section === $item, 'text-primary hover:bg-canvas' => $section !== $item])>
                            {{ __('owner.settings.groups.'.$item.'.title') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0 space-y-4">
            @if (in_array($section, ['general', 'localization', 'pricing', 'mail'], true))
                <section class="rounded-md border border-line bg-surface p-5">
                    <h2 class="text-base font-semibold text-primary">{{ __('owner.settings.groups.'.$section.'.title') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('owner.settings.groups.'.$section.'.description') }}</p>
                    <dl class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted" aria-label="{{ __('owner.settings.sources.title') }}">
                        @foreach ($values as $id => $_value)
                            <div><dt class="inline">{{ __('owner.settings.'.$section.'.'.substr($id, strlen($section) + 1)) }}:</dt>
                                <dd class="inline">{{ $settingSources[$id] === 'owner' ? __('owner.settings.sources.owner') : __('owner.settings.sources.config') }}</dd></div>
                        @endforeach
                    </dl>

                    @if ($section === 'general')
                        <form method="POST" action="{{ route('owner.settings.update', 'general') }}" class="mt-5 space-y-4">
                            @csrf @method('PUT')
                            <label class="block text-sm font-medium">{{ __('owner.settings.general.business_name') }}
                                <input name="settings[general.business_name]" value="{{ old('settings.general.business_name', $values['general.business_name']) }}" maxlength="120" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.general.support_email') }}
                                <input type="email" name="settings[general.support_email]" value="{{ old('settings.general.support_email', $values['general.support_email']) }}" maxlength="255" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                            </label>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block text-sm font-medium">{{ __('owner.settings.general.timezone') }}
                                    <input name="settings[general.timezone]" value="{{ old('settings.general.timezone', $values['general.timezone']) }}" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.general.country') }}
                                <select name="settings[general.country]" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach ($countries as $code => $country)<option value="{{ $code }}" @selected(old('settings.general.country', $values['general.country']) === $code)>{{ $country }} ({{ $code }})</option>@endforeach
                                </select>
                                </label>
                            </div>
                            <button class="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ __('owner.settings.save') }}</button>
                        </form>
                    @elseif ($section === 'localization')
                        <form method="POST" action="{{ route('owner.settings.update', 'localization') }}" class="mt-5 space-y-4">
                            @csrf @method('PUT')
                            <label class="block text-sm font-medium">{{ __('owner.settings.localization.default_language') }}
                                <select name="settings[localization.default_language]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (['hu' => 'Magyar (hu)', 'en' => 'English (en)'] as $code => $label)
                                        <option value="{{ $code }}" @selected(old('settings.localization.default_language', $values['localization.default_language']) === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ __('owner.settings.save') }}</button>
                        </form>
                        <div class="mt-5 border-t border-line pt-4">
                            <h3 class="text-sm font-semibold">{{ __('owner.settings.localization.currency_suggestions') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('owner.settings.localization.currency_suggestions_note') }}</p>
                            <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach ($localeSuggestions as $locales => $currency)
                                    <div class="rounded-sm bg-canvas p-2 text-xs"><dt class="font-mono text-muted">{{ $locales }}</dt><dd class="mt-1 font-semibold">{{ $currency }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @elseif ($section === 'pricing')
                        <p class="mt-4 rounded-sm border border-warning/40 bg-warning-soft p-3 text-sm text-warning-ink">{{ __('owner.settings.pricing.not_live') }}</p>
                        <form method="POST" action="{{ route('owner.settings.update', 'pricing') }}" class="mt-5 space-y-4">
                            @csrf @method('PUT')
                            <fieldset>
                                <legend class="text-sm font-medium">{{ __('owner.settings.pricing.enabled_currencies') }}</legend>
                                <div class="mt-2 flex flex-wrap gap-4">
                                    @foreach (\App\Owner\OwnerSettings::CURRENCIES as $currency)
                                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="settings[pricing.enabled_currencies][]" value="{{ $currency }}" @checked(in_array($currency, old('settings.pricing.enabled_currencies', $values['pricing.enabled_currencies']), true))>{{ $currency }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <label class="block text-sm font-medium">{{ __('owner.settings.pricing.default_international_currency') }}
                                <select name="settings[pricing.default_international_currency]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (['EUR', 'USD', 'GBP'] as $currency)<option value="{{ $currency }}" @selected(old('settings.pricing.default_international_currency', $values['pricing.default_international_currency']) === $currency)>{{ $currency }}</option>@endforeach
                                </select>
                            </label>
                            <p class="text-xs text-muted">{{ __('owner.settings.pricing.hungary_rule') }}</p>
                            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach (['markets', 'monthly_prices', 'annual_rule', 'stripe_verification'] as $concept)
                                    <div class="rounded-sm bg-canvas p-3"><dt class="text-xs text-muted">{{ __('owner.settings.pricing.'.$concept) }}</dt><dd class="mt-1 text-sm">{{ __('owner.settings.pricing.'.$concept.'_status') }}</dd></div>
                                @endforeach
                            </dl>
                            <button class="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ __('owner.settings.save') }}</button>
                        </form>
                    @elseif ($section === 'mail')
                        <p class="mt-2 text-xs text-muted">{{ __('owner.settings.mail.current_driver', ['driver' => $currentMailer]) }}</p>
                        <form method="POST" action="{{ route('owner.settings.update', 'mail') }}" class="mt-5 space-y-4">
                            @csrf @method('PUT')
                            <label class="block text-sm font-medium">{{ __('owner.settings.mail.mode') }}
                                <select name="settings[mail.mode]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    <option value="local_smtp" @selected(old('settings.mail.mode', $values['mail.mode']) === 'local_smtp')>{{ __('owner.settings.mail.local_smtp') }}</option>
                                    <option value="external_smtp" @selected(old('settings.mail.mode', $values['mail.mode']) === 'external_smtp')>{{ __('owner.settings.mail.external_smtp') }}</option>
                                </select>
                            </label>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block text-sm font-medium">{{ __('owner.settings.mail.host') }}
                                    <input name="settings[mail.host]" value="{{ old('settings.mail.host', $values['mail.host']) }}" maxlength="253" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 font-mono text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.mail.port') }}
                                    <input type="number" name="settings[mail.port]" value="{{ old('settings.mail.port', $values['mail.port']) }}" min="1" max="65535" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.mail.username') }}
                                    <input name="settings[mail.username]" value="{{ old('settings.mail.username', $values['mail.username']) }}" maxlength="255" autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.mail.scheme') }}
                                    <select name="settings[mail.scheme]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                        <option value="" @selected(old('settings.mail.scheme', $values['mail.scheme']) === null)>{{ __('owner.settings.mail.scheme_auto') }}</option>
                                        <option value="smtp" @selected(old('settings.mail.scheme', $values['mail.scheme']) === 'smtp')>SMTP / STARTTLS</option>
                                        <option value="smtps" @selected(old('settings.mail.scheme', $values['mail.scheme']) === 'smtps')>SMTPS / TLS</option>
                                    </select>
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.mail.from_address') }}
                                    <input type="email" name="settings[mail.from_address]" value="{{ old('settings.mail.from_address', $values['mail.from_address']) }}" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.mail.from_name') }}
                                    <input name="settings[mail.from_name]" value="{{ old('settings.mail.from_name', $values['mail.from_name']) }}" maxlength="120" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                            </div>
                            <div class="rounded-sm bg-canvas p-3 text-sm">
                                <p>{{ __('owner.settings.mail.password_status') }} <strong>{{ $passwordConfigured ? __('owner.settings.mail.configured') : __('owner.settings.mail.not_configured') }}</strong></p>
                                @if (old('settings.mail.mode', $values['mail.mode']) === 'local_smtp')
                                    <p class="mt-1 text-xs text-muted">{{ __('owner.settings.mail.local_password_note') }}</p>
                                @else
                                    <p class="mt-1 text-xs text-muted">{{ __('owner.settings.mail.external_password_note') }}</p>
                                @endif
                                <a class="mt-2 inline-block text-sm font-medium underline" href="{{ route('owner.integrations.index') }}">{{ __('owner.settings.mail.service_credentials_link') }}</a>
                            </div>
                            <button class="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ __('owner.settings.save') }}</button>
                        </form>
                        <form method="POST" action="{{ route('owner.settings.mail.test') }}" class="mt-6 border-t border-line pt-4">
                            @csrf
                            <h3 class="text-sm font-semibold">{{ __('owner.settings.mail.test_title') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('owner.settings.mail.test_note') }}</p>
                            @unless ($testMailAvailable)<p class="mt-2 text-xs text-warning-ink">{{ __('owner.settings.mail.test_unavailable') }}</p>@endunless
                            <label class="mt-3 block text-sm font-medium">{{ __('owner.settings.mail.test_recipient') }}
                                <input type="email" name="recipient" value="{{ old('recipient') }}" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                            </label>
                            <button @disabled(! $testMailAvailable) class="mt-3 h-10 rounded-sm border border-line-strong px-4 text-sm font-semibold text-primary disabled:cursor-not-allowed disabled:opacity-50">{{ __('owner.settings.mail.send_test') }}</button>
                        </form>
                    @endif
                </section>
            @elseif ($section === 'backups')
                <section class="rounded-md border border-line bg-surface p-5">
                    <h2 class="font-semibold">{{ __('owner.settings.groups.backups.title') }}</h2><p class="mt-1 text-sm text-muted">{{ __('owner.settings.groups.backups.description') }}</p>
                    <x-ui.status-badge tone="neutral">{{ __('owner.settings.not_implemented') }}</x-ui.status-badge>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">@foreach (['enabled', 'frequency', 'retention', 'provider', 'prefix', 'last_backup', 'last_verified', 'restore_history'] as $field)<div class="rounded-sm bg-canvas p-3"><dt class="text-xs text-muted">{{ __('owner.settings.backups.'.$field) }}</dt><dd class="mt-1 text-sm">{{ __('owner.settings.not_implemented') }}</dd></div>@endforeach</dl>
                    <p class="mt-3 text-xs text-muted">{{ __('owner.settings.backups.secrets_note') }}</p>
                </section>
            @elseif ($section === 'billing_tax')
                <section class="rounded-md border border-line bg-surface p-5">
                    <h2 class="font-semibold">{{ __('owner.settings.groups.billing_tax.title') }}</h2><p class="mt-1 text-sm text-muted">{{ __('owner.settings.groups.billing_tax.description') }}</p>
                    <x-ui.status-badge tone="neutral">{{ __('owner.settings.not_implemented') }}</x-ui.status-badge>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">@foreach (['invoicing', 'provider', 'country_rules', 'customer_type', 'vat_id', 'vies', 'invoice_language', 'automatic_invoice'] as $field)<div class="rounded-sm bg-canvas p-3"><dt class="text-xs text-muted">{{ __('owner.settings.billing_tax.'.$field) }}</dt><dd class="mt-1 text-sm">{{ __('owner.settings.not_implemented') }}</dd></div>@endforeach</dl>
                    <p class="mt-3 text-xs text-muted">{{ __('owner.settings.billing_tax.no_tax_rules') }}</p>
                </section>
            @else
                <section class="rounded-md border border-line bg-surface p-5">
                    <h2 class="font-semibold">{{ __('owner.settings.groups.system.title') }}</h2><p class="mt-1 text-sm text-muted">{{ __('owner.settings.groups.system.description') }}</p>
                    <x-ui.status-badge tone="positive">{{ __('owner.settings.read_only') }}</x-ui.status-badge>
                    <p class="mt-3 text-sm text-muted">{{ __('owner.settings.system.safe_note') }}</p>
                    <a href="{{ route('owner.system') }}" class="mt-3 inline-block text-sm font-medium underline">{{ __('owner.settings.system.status_link') }}</a>
                </section>
            @endif
        </div>
    </div>
</x-layouts.owner>
