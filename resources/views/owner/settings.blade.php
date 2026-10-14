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
                            <div class="mt-5">
                                <h3 class="text-sm font-semibold">{{ __('owner.settings.pricing.price_matrix') }}</h3>
                                <div class="mt-2 overflow-x-auto rounded-sm border border-line">
                                    <table class="w-full min-w-[640px] text-left text-xs">
                                        <thead class="bg-canvas text-muted"><tr>
                                            @foreach (['price_plan', 'price_market', 'price_interval', 'price_amount', 'price_stripe_status'] as $column)
                                                <th class="px-3 py-2">{{ __('owner.settings.pricing.'.$column) }}</th>
                                            @endforeach
                                        </tr></thead>
                                        <tbody class="divide-y divide-line">
                                            @foreach ($pricingRows as $row)
                                                @php $price = $row['price']; @endphp
                                                <tr>
                                                    <td class="px-3 py-2">{{ $price->productType->label() }} {{ __('owner.tiers.'.$price->tier->value) }}</td>
                                                    <td class="px-3 py-2">{{ $price->marketGroup }} · {{ $price->money->currency->code }}</td>
                                                    <td class="px-3 py-2">{{ $price->interval->label() }}</td>
                                                    <td class="px-3 py-2 tabular-nums">{{ $price->money->format() }}</td>
                                                    <td class="px-3 py-2">{{ __('owner.settings.pricing.price_status.'.$row['status']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
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
                    <p class="mt-3 rounded-sm border border-warning/40 bg-warning-soft p-3 text-sm text-warning-ink">{{ __('owner.settings.billing_tax.safety_notice') }}</p>
                    <section class="mt-6 border-t border-line pt-5">
                        <h3 class="font-semibold">{{ __('owner.settings.billing_tax.seller_profile_management.title') }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ __('owner.settings.billing_tax.seller_profile_management.lead') }}</p>
                        <p class="mt-3 rounded-sm border border-warning/40 bg-warning-soft p-3 text-sm text-warning-ink">{{ __('owner.settings.billing_tax.seller_profile_management.accounting_notice') }}</p>
                        <div class="mt-4 space-y-3">
                            @forelse ($sellerProfileRows as $row)
                                <article class="rounded-sm border border-line bg-canvas p-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <strong>{{ $row['profile']->legal_name }}</strong>
                                        <span>v{{ $row['profile']->version }} · {{ __('owner.settings.billing_tax.seller_profile_management.statuses.'.$row['profile']->verification_status->value) }}</span>
                                    </div>
                                    <p class="mt-1 text-muted">{{ $row['profile']->establishment_country }} · {{ $row['profile']->effective_from?->toDateString() }} — {{ $row['profile']->effective_to?->toDateString() ?? '∞' }}</p>
                                    <p class="mt-1">{{ $row['missing_fields'] === [] ? __('owner.settings.billing_tax.seller_profile_management.complete') : __('owner.settings.billing_tax.seller_profile_management.incomplete', ['fields' => implode(', ', $row['missing_fields'])]) }}</p>
                                    @if ($row['profile']->supersedes_profile_id)<p class="mt-1 text-xs text-muted">{{ __('owner.settings.billing_tax.seller_profile_management.supersedes', ['version' => $row['profile']->supersedes?->version ?? '—']) }}</p>@endif
                                </article>
                            @empty
                                <p class="rounded-sm bg-canvas p-3 text-sm text-muted">{{ __('owner.settings.billing_tax.seller_profile_management.empty') }}</p>
                            @endforelse
                        </div>
                        <form method="POST" action="{{ route('owner.settings.seller-profiles.store') }}" class="mt-5 space-y-4">
                            @csrf
                            <h4 class="text-sm font-semibold">{{ __('owner.settings.billing_tax.seller_profile_management.new_version') }}</h4>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.supersedes_label') }}
                                <select name="supersedes_profile_id" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    <option value="">{{ __('owner.settings.billing_tax.seller_profile_management.no_supersession') }}</option>
                                    @foreach ($billingTaxProfiles as $profile)<option value="{{ $profile->id }}">{{ __('owner.settings.billing_tax.seller_profile_management.supersede_option', ['version' => $profile->version, 'name' => $profile->legal_name]) }}</option>@endforeach
                                </select>
                            </label>
                            <p class="text-xs text-muted">{{ __('owner.settings.billing_tax.seller_profile_management.supersession_note') }}</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.legal_name') }}
                                    <input name="legal_name" maxlength="200" autocomplete="organization" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.establishment_country') }}
                                    <input name="establishment_country" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="country" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.address_line1') }}
                                    <input name="seller_address[address_line1]" maxlength="150" autocomplete="address-line1" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.address_line2') }}
                                    <input name="seller_address[address_line2]" maxlength="150" autocomplete="address-line2" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.postal_code') }}
                                    <input name="seller_address[postal_code]" maxlength="30" autocomplete="postal-code" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.city') }}
                                    <input name="seller_address[city]" maxlength="120" autocomplete="address-level2" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.region') }}
                                    <input name="seller_address[region]" maxlength="120" autocomplete="address-level1" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.address_country') }}
                                    <input name="seller_address[country_code]" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="country" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.aam_status') }}
                                    <select name="aam_status" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                        @foreach (\App\Tax\AamStatus::cases() as $status)<option value="{{ $status->value }}">{{ __('owner.settings.billing_tax.seller_profile_management.aam_statuses.'.$status->value) }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.verification_status') }}
                                    <select name="verification_status" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                        @foreach ([\App\Tax\SellerTaxProfileStatus::Unverified, \App\Tax\SellerTaxProfileStatus::PendingVerification, \App\Tax\SellerTaxProfileStatus::Verified, \App\Tax\SellerTaxProfileStatus::ManualReview] as $status)<option value="{{ $status->value }}">{{ __('owner.settings.billing_tax.seller_profile_management.statuses.'.$status->value) }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.evidence_reference') }}
                                    <input name="evidence_reference" maxlength="200" autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.aam_evidence_reference') }}
                                    <input name="aam_evidence_reference" maxlength="200" autocomplete="off" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.effective_from') }}
                                    <input type="date" name="effective_from" required value="{{ now()->toDateString() }}" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                                <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile_management.effective_to') }}
                                    <input type="date" name="effective_to" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                </label>
                            </div>
                            <button class="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ __('owner.settings.billing_tax.seller_profile_management.publish') }}</button>
                        </form>
                    </section>
                    <form method="POST" action="{{ route('owner.settings.update', 'billing_tax') }}" class="mt-5 space-y-4">
                        @csrf @method('PUT')
                        <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.seller_profile') }}
                            <select name="settings[seller_profile_id]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                <option value="">{{ __('owner.settings.billing_tax.no_seller_profile') }}</option>
                                @foreach ($billingTaxProfiles as $profile)
                                    <option value="{{ $profile->id }}" @selected((string) old('settings.seller_profile_id', $billingTaxValues['seller_profile_id'] ?? '') === (string) $profile->id)>{{ $profile->legal_name ?: __('owner.settings.billing_tax.unnamed_profile') }} · v{{ $profile->version }} · {{ $profile->verification_status?->value }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.invoice_language') }}
                                <select name="settings[invoice_language]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (\App\Billing\SzamlazzBillingSettings::LANGUAGES as $language)<option value="{{ $language }}" @selected(old('settings.invoice_language', $billingTaxValues['invoice_language']) === $language)>{{ strtoupper($language) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.payment_method') }}
                                <select name="settings[default_payment_method]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (\App\Billing\SzamlazzBillingSettings::PAYMENT_METHODS as $method)<option value="{{ $method }}" @selected(old('settings.default_payment_method', $billingTaxValues['default_payment_method']) === $method)>{{ __('owner.settings.billing_tax.payment_methods.'.$method) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.payment_deadline') }}
                                <select name="settings[payment_deadline_policy]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (\App\Billing\SzamlazzBillingSettings::PAYMENT_DEADLINES as $policy)<option value="{{ $policy }}" @selected(old('settings.payment_deadline_policy', $billingTaxValues['payment_deadline_policy']) === $policy)>{{ __('owner.settings.billing_tax.payment_deadlines.'.$policy) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.fulfillment_date') }}
                                <select name="settings[fulfillment_date_policy]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (\App\Billing\SzamlazzBillingSettings::FULFILLMENT_POLICIES as $policy)<option value="{{ $policy }}" @selected(old('settings.fulfillment_date_policy', $billingTaxValues['fulfillment_date_policy']) === $policy)>{{ __('owner.settings.billing_tax.fulfillment_policies.'.$policy) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.currency_handling') }}
                                <select name="settings[currency_policy]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (\App\Billing\SzamlazzBillingSettings::CURRENCY_POLICIES as $policy)<option value="{{ $policy }}" @selected(old('settings.currency_policy', $billingTaxValues['currency_policy']) === $policy)>{{ __('owner.settings.billing_tax.currency_policies.'.$policy) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.tax_mode') }}
                                <select name="settings[tax_configuration_mode]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    @foreach (\App\Billing\SzamlazzBillingSettings::TAX_MODES as $mode)<option value="{{ $mode }}" @selected(old('settings.tax_configuration_mode', $billingTaxValues['tax_configuration_mode']) === $mode)>{{ __('owner.settings.billing_tax.tax_modes.'.$mode) }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.default_tax_code') }}
                                <select name="settings[default_tax_code]" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                                    <option value="">{{ __('owner.settings.billing_tax.no_default_tax_code') }}</option>
                                    @foreach ($billingTaxCodes as $code => $description)<option value="{{ $code }}" @selected(old('settings.default_tax_code', $billingTaxValues['default_tax_code']) === (string) $code)>{{ $code }} — {{ $description }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.invoice_prefix') }}
                                <input name="settings[invoice_prefix]" value="{{ old('settings.invoice_prefix', $billingTaxValues['invoice_prefix'] ?? null) }}" maxlength="16" autocomplete="off" autocapitalize="characters" class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 font-mono text-sm">
                                <span class="mt-1 block text-xs text-muted">{{ __('owner.settings.billing_tax.invoice_prefix_description') }}</span>
                                <span class="mt-1 block text-xs text-muted">{{ __('owner.settings.billing_tax.invoice_prefix_helper') }}</span>
                            </label>
                            <label class="block text-sm font-medium">{{ __('owner.settings.billing_tax.effective_date') }}
                                <input type="date" name="settings[effective_at]" value="{{ old('settings.effective_at', now()->toDateString()) }}" required class="mt-1 block h-10 w-full rounded-sm border border-line-strong bg-surface px-3 text-sm">
                            </label>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="settings[notify_customer]" value="1" @checked((bool) old('settings.notify_customer', $billingTaxValues['notify_customer']))>{{ __('owner.settings.billing_tax.notify_customer') }}</label>
                        <p class="text-xs text-muted">{{ __('owner.settings.billing_tax.tax_code_notice') }}</p>
                        <button class="h-10 rounded-sm bg-primary px-4 text-sm font-semibold text-white">{{ __('owner.settings.save') }}</button>
                    </form>
                    <h3 class="mb-2 mt-7 text-sm font-semibold">{{ __('owner.settings.billing_tax.issuance_status') }}</h3>
                    <dl class="grid gap-2 sm:grid-cols-3">
                        @foreach ($invoiceIssuanceGates as $gate => $enabled)
                            <div class="rounded-sm bg-canvas p-3"><dt class="text-xs text-muted">{{ __('owner.settings.billing_tax.gates.'.$gate) }}</dt><dd class="mt-1 text-sm font-semibold">{{ $enabled ? __('owner.settings.billing_tax.enabled') : __('owner.settings.billing_tax.disabled') }}</dd></div>
                        @endforeach
                    </dl>
                    <h3 class="mb-2 mt-7 text-sm font-semibold">{{ __('owner.settings.billing_tax.configuration_history') }}</h3>
                    <div class="overflow-x-auto rounded-sm border border-line"><table class="w-full min-w-[560px] text-left text-xs"><thead class="bg-canvas text-muted"><tr><th class="px-3 py-2">{{ __('owner.settings.billing_tax.version') }}</th><th class="px-3 py-2">{{ __('owner.settings.billing_tax.effective_date') }}</th><th class="px-3 py-2">{{ __('owner.settings.billing_tax.invoice_prefix') }}</th><th class="px-3 py-2">{{ __('owner.settings.billing_tax.tax_mode') }}</th><th class="px-3 py-2">{{ __('owner.settings.billing_tax.default_tax_code') }}</th><th class="px-3 py-2">{{ __('owner.settings.billing_tax.changed_by') }}</th></tr></thead><tbody class="divide-y divide-line">@forelse ($billingTaxVersions as $version)<tr><td class="px-3 py-2">v{{ $version->version }}</td><td class="px-3 py-2">{{ $version->effective_at?->translatedFormat('j M Y') }}</td><td class="px-3 py-2 font-mono">{{ ($version->configuration['invoice_prefix'] ?? null) ?: '—' }}</td><td class="px-3 py-2">{{ __('owner.settings.billing_tax.tax_modes.'.$version->configuration['tax_configuration_mode']) }}</td><td class="px-3 py-2">{{ $version->configuration['default_tax_code'] ?: '—' }}</td><td class="px-3 py-2">{{ $version->creator?->email ?? '—' }}</td></tr>@empty<tr><td colspan="6" class="px-3 py-3 text-muted">{{ __('owner.settings.billing_tax.no_versions') }}</td></tr>@endforelse</tbody></table></div>
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
