{{--
    The operator permissions as explicit checkboxes, nothing ticked unless
    granted. Sensitive ones are marked and need the confirmation box.
--}}
@props(['permissions', 'sensitive', 'granted' => []])

@php
    $selected = old('permissions', array_map(fn ($p) => $p->value, $granted));
    $groups = collect($permissions)->groupBy(fn ($p) => explode('.', $p->value)[0]);
@endphp

<fieldset class="min-w-0">
    <legend class="text-sm font-medium text-primary">{{ __('owner.operators.permissions') }}</legend>
    <p class="mt-1 text-xs text-muted">{{ __('owner.operators.permissions_hint') }}</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($groups as $group => $items)
            <div class="min-w-0 rounded-sm border border-line p-3">
                <p class="text-xs font-semibold tracking-[0.08em] text-muted uppercase">{{ __('owner.operators.groups.'.$group) }}</p>
                <ul class="mt-2 space-y-2">
                    @foreach ($items as $permission)
                        @php $isSensitive = in_array($permission, $sensitive, true); @endphp
                        <li>
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="permissions[]" value="{{ $permission->value }}" @checked(in_array($permission->value, (array) $selected, true)) class="mt-0.5 size-4 rounded-sm border-line-strong accent-[#4F7D65]">
                                <span class="min-w-0">
                                    <span class="block text-primary">{{ $permission->label() }}</span>
                                    <span class="block font-mono text-[11px] break-all text-muted">{{ $permission->value }}</span>
                                    @if ($isSensitive)
                                        <span class="mt-0.5 block text-xs text-warning-ink">{{ ((array) __('owner.operators.sensitive'))[$permission->value] ?? '' }}</span>
                                    @endif
                                </span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
    <label class="mt-4 flex items-start gap-2 rounded-sm border border-warning/40 bg-warning-soft px-3 py-2 text-sm text-warning-ink">
        <input type="hidden" name="confirm_sensitive" value="0">
        <input type="checkbox" name="confirm_sensitive" value="1" class="mt-0.5 size-4">
        <span>{{ __('owner.operators.confirm_sensitive') }}</span>
    </label>
</fieldset>
