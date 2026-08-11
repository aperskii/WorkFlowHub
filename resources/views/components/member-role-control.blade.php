@props([
    'membership',
    'assignableRoles',
])

@php
    // Which roles this actor may grant to this member is decided per row by the
    // MembershipPolicy, never by the role list alone.
    $assignableForMember = collect($assignableRoles)
        ->filter(fn (App\Enums\OrganizationRole $role) => Illuminate\Support\Facades\Gate::allows('updateRole', [$membership, $role]));
@endphp

@if ($assignableForMember->isNotEmpty())
    <flux:dropdown position="bottom" align="start">
        <flux:button
            size="sm"
            variant="ghost"
            icon-trailing="chevron-down"
            :data-test="'change-role-'.$membership->id"
        >
            {{ $membership->role->label() }}
        </flux:button>

        <flux:menu>
            @foreach ($assignableForMember as $role)
                <flux:menu.item
                    wire:click="updateRole({{ $membership->id }}, '{{ $role->value }}')"
                    :disabled="$role === $membership->role"
                    :data-test="'assign-role-'.$membership->id.'-'.$role->value"
                >
                    {{ $role->label() }}
                </flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
@else
    <flux:badge size="sm" inset="top bottom" :color="$membership->role->color()">
        {{ $membership->role->label() }}
    </flux:badge>
@endif
