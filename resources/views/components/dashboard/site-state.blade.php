{{--
    Update, security or license state of one site, shown as icon + text so
    it never relies on colour alone.
    type: updates | security | license
--}}
@props(['type', 'site'])

@php
    [$tone, $label] = match ($type) {
        'updates' => $site['updates'] === 0
            ? ['positive', __('dashboard.states.updates.none')]
            : ['warning', __('dashboard.states.updates.pending', ['count' => $site['updates']])],
        'security' => $site['security'] === 'ok'
            ? ['positive', __('dashboard.states.security.ok')]
            : ['danger', __('dashboard.states.security.issue')],
        'license' => match ($site['license']) {
            'valid' => ['positive', __('dashboard.states.license.valid')],
            'expired' => ['warning', __('dashboard.states.license.expired')],
            default => ['neutral', __('dashboard.states.license.none')],
        },
    };

    $styles = [
        'positive' => ['check', 'text-ink', 'text-accent-ink'],
        'warning' => ['alert-triangle', 'text-warning-ink', 'text-warning-ink'],
        'danger' => ['alert-circle', 'text-danger-ink', 'text-danger-ink'],
        'neutral' => ['minus', 'text-muted', 'text-muted'],
    ];
    [$icon, $textClass, $iconClass] = $styles[$tone];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 whitespace-nowrap '.$textClass]) }}>
    <x-ui.icon :name="$icon" class="size-3.5 {{ $iconClass }}" />
    {{ $label }}
</span>
