<x-layouts.owner :title="__('owner.operators.title')">
    <x-operator.page-header :title="__('owner.operators.title')" :lead="__('owner.operators.lead')" />

    <x-operator.section :title="__('owner.operators.list')" id="operator-list">
        @if ($operators->isEmpty())
            <p class="text-sm text-muted">{{ __('owner.operators.empty') }}</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($operators as $operator)
                    @php $state = $operator->operator_disabled_at ? 'disabled' : ($operator->hasVerifiedEmail() ? 'active' : 'invited'); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span class="min-w-0">
                            <a href="{{ route('owner.operators.show', $operator) }}" class="block font-medium break-words text-primary hover:underline">{{ $operator->name }}</a>
                            <span class="block text-xs break-all text-muted">{{ $operator->email }}</span>
                        </span>
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-xs text-muted">{{ trans_choice('owner.operators.permission_count', count($operator->operatorPermissions())) }}</span>
                            <x-ui.status-badge :tone="['active' => 'positive', 'invited' => 'warning', 'disabled' => 'neutral'][$state]">{{ __('owner.operators.states.'.$state) }}</x-ui.status-badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-operator.section>

    <x-operator.section :title="__('owner.operators.invite')" id="operator-invite" class="mt-4">
        <p class="mb-4 text-sm text-muted">{{ __('owner.operators.invite_lead') }}</p>
        <form method="POST" action="{{ route('owner.operators.store') }}" class="grid gap-4" novalidate>
            @csrf
            <div class="grid gap-4 md:grid-cols-2">
                <x-form.field name="name" :label="__('owner.operators.name')" required maxlength="100" />
                <x-form.field name="email" type="email" :label="__('owner.operators.email')" required maxlength="254" />
            </div>
            <x-owner.permission-checkboxes :permissions="$permissions" :sensitive="$sensitive" />
            <div>
                <x-ui.button type="submit">{{ __('owner.operators.send_invitation') }}</x-ui.button>
            </div>
        </form>
    </x-operator.section>
</x-layouts.owner>
