{{-- One label/value pair inside a <dl>. An empty slot shows a dash. --}}
@props(['label'])

<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <dt class="text-muted">{{ $label }}</dt>
    <dd class="mt-1 font-medium break-words text-primary">
        @if ($slot->isEmpty()){{ __('account.subscription.not_available') }}@else{{ $slot }}@endif
    </dd>
</div>
