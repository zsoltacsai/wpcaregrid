<x-layouts.owner :title="$operator->name">
    @php $state = $operator->operator_disabled_at ? 'disabled' : ($operator->hasVerifiedEmail() ? 'active' : 'invited'); @endphp

    <x-operator.page-header :title="$operator->name" :lead="$operator->email" :eyebrow="__('owner.operators.title')">
        <x-slot:actions>
            <x-ui.status-badge :tone="['active' => 'positive', 'invited' => 'warning', 'disabled' => 'neutral'][$state]">{{ __('owner.operators.states.'.$state) }}</x-ui.status-badge>
        </x-slot:actions>
    </x-operator.page-header>

    <x-operator.section :title="__('owner.operators.access')" id="operator-access">
        <p class="text-sm text-muted">{{ __('owner.operators.access_lead.'.$state) }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            @if ($state === 'disabled')
                <form method="POST" action="{{ route('owner.operators.enable', $operator) }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('owner.operators.enable') }}</x-ui.button>
                </form>
            @else
                <form method="POST" action="{{ route('owner.operators.disable', $operator) }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="danger">{{ __('owner.operators.disable') }}</x-ui.button>
                </form>
            @endif
            @if ($state === 'invited')
                <form method="POST" action="{{ route('owner.operators.invitation', $operator) }}">
                    @csrf
                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('owner.operators.resend') }}</x-ui.button>
                </form>
            @endif
        </div>
    </x-operator.section>

    <x-operator.section :title="__('owner.operators.permissions')" id="operator-permissions" class="mt-4">
        <form method="POST" action="{{ route('owner.operators.permissions', $operator) }}" class="grid gap-4" novalidate>
            @csrf
            @method('PUT')
            <x-owner.permission-checkboxes :permissions="$permissions" :sensitive="$sensitive" :granted="$operator->operatorPermissions()" />
            @if (! config('admin_access.operator_full_admin'))
                <p class="text-xs [overflow-wrap:anywhere] text-muted">{{ __('owner.operators.full_admin_off') }}</p>
            @endif
            <div>
                <x-ui.button type="submit">{{ __('owner.operators.save_permissions') }}</x-ui.button>
            </div>
        </form>
    </x-operator.section>

    <x-operator.section :title="__('owner.operators.history')" id="operator-history" class="mt-4">
        <x-owner.audit-list :events="$audit" />
    </x-operator.section>
</x-layouts.owner>
