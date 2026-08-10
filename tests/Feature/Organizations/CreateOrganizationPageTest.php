<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

test('a guest cannot open the create organization page', function () {
    $this->get(route('organizations.create'))->assertRedirect(route('login'));
});

test('an unverified user cannot open the create organization page', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('organizations.create'))
        ->assertRedirect(route('verification.notice'));
});

test('a verified user can open the create organization page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('organizations.create'))
        ->assertOk()
        ->assertSee('Create organization');
});

test('creating an organization persists it and makes the creator its only owner', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.create')
        ->set('name', 'Acme Agency')
        ->call('createOrganization')
        ->assertHasNoErrors();

    $organization = Organization::sole();

    expect($organization->name)->toBe('Acme Agency')
        ->and($organization->slug)->toBe('acme-agency')
        ->and($organization->memberships()->count())->toBe(1)
        ->and($organization->owners()->count())->toBe(1);

    $membership = $user->membershipFor($organization);

    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(OrganizationRole::Owner);
});

test('creating an organization redirects to the new organization dashboard', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.create')
        ->set('name', 'Acme Agency')
        ->call('createOrganization')
        ->assertRedirect(route('organizations.dashboard', Organization::sole()));
});

test('the creator can immediately reach the new organization dashboard', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.create')
        ->set('name', 'Acme Agency')
        ->call('createOrganization');

    $this->actingAs($user)
        ->get(route('organizations.dashboard', Organization::sole()))
        ->assertOk()
        ->assertSee('Acme Agency')
        ->assertSee(OrganizationRole::Owner->label());
});

test('a user can create multiple organizations and owns each of them', function () {
    $user = User::factory()->create();

    foreach (['First Agency', 'Second Agency'] as $name) {
        Livewire::actingAs($user)
            ->test('pages::organizations.create')
            ->set('name', $name)
            ->call('createOrganization')
            ->assertHasNoErrors();
    }

    expect(Organization::count())->toBe(2)
        ->and($user->organizations()->count())->toBe(2);

    foreach (Organization::all() as $organization) {
        expect($user->membershipFor($organization)->role)->toBe(OrganizationRole::Owner);
    }
});

test('slug collisions are resolved when the same name is used twice', function () {
    $user = User::factory()->create();

    foreach (['Acme Agency', 'Acme Agency'] as $name) {
        Livewire::actingAs($user)
            ->test('pages::organizations.create')
            ->set('name', $name)
            ->call('createOrganization')
            ->assertHasNoErrors();
    }

    expect(Organization::pluck('slug')->all())->toEqualCanonicalizing(['acme-agency', 'acme-agency-2']);
});

test('the organization name is required', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.create')
        ->set('name', '')
        ->call('createOrganization')
        ->assertHasErrors(['name' => 'required']);

    expect(Organization::count())->toBe(0);
});

test('the organization name may not exceed 255 characters', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.create')
        ->set('name', str_repeat('a', 256))
        ->call('createOrganization')
        ->assertHasErrors(['name' => 'max']);

    expect(Organization::count())->toBe(0);
});

test('a failed creation leaves no organization behind', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.create')
        ->set('name', '')
        ->call('createOrganization')
        ->assertHasErrors('name')
        ->assertNoRedirect();

    expect(Organization::count())->toBe(0)
        ->and($user->memberships()->count())->toBe(0);
});

test('creating an organization does not grant membership to anyone else', function () {
    $creator = User::factory()->create();
    $bystander = User::factory()->create();

    Livewire::actingAs($creator)
        ->test('pages::organizations.create')
        ->set('name', 'Acme Agency')
        ->call('createOrganization');

    $organization = Organization::sole();

    expect($organization->hasMember($creator))->toBeTrue()
        ->and($organization->hasMember($bystander))->toBeFalse()
        ->and($bystander->organizations()->count())->toBe(0);
});

test('another user cannot see or access an organization they did not create', function () {
    $creator = User::factory()->create();
    $outsider = User::factory()->create();

    Livewire::actingAs($creator)
        ->test('pages::organizations.create')
        ->set('name', 'Acme Agency')
        ->call('createOrganization');

    $organization = Organization::sole();

    $this->actingAs($outsider)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Acme Agency');

    $this->actingAs($outsider)
        ->get(route('organizations.dashboard', $organization))
        ->assertForbidden();
});
