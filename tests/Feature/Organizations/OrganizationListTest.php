<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

test('a guest cannot access the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('an unverified user is redirected to email verification', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('the dashboard shows an empty state when the user belongs to no organizations', function () {
    $user = User::factory()->create();

    Organization::factory()->create(['name' => 'Someone Elses Org']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('You don\'t belong to any organizations yet.')
        ->assertDontSee('Someone Elses Org');
});

test('the dashboard offers a create organization action in the empty state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('organizations.create', absolute: false), escape: false);
});

test('the dashboard lists the organizations the user belongs to', function () {
    $user = User::factory()->create();

    $first = Organization::factory()->create(['name' => 'First Org', 'slug' => 'first-org']);
    $second = Organization::factory()->create(['name' => 'Second Org', 'slug' => 'second-org']);

    Membership::factory()->for($first)->for($user)->owner()->create();
    Membership::factory()->for($second)->for($user)->employee()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('First Org')
        ->assertSee('first-org')
        ->assertSee('Second Org')
        ->assertSee('second-org');
});

test('the dashboard shows the user role for each organization', function () {
    $user = User::factory()->create();

    $owned = Organization::factory()->create(['name' => 'Owned Org']);
    $employed = Organization::factory()->create(['name' => 'Employed Org']);

    Membership::factory()->for($owned)->for($user)->owner()->create();
    Membership::factory()->for($employed)->for($user)->manager()->create();

    Livewire::actingAs($user)
        ->test('pages::organizations.list')
        ->assertSee(OrganizationRole::Owner->label())
        ->assertSee(OrganizationRole::Manager->label())
        ->assertDontSee(OrganizationRole::Employee->label());
});

test('the dashboard never lists another user\'s organizations', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $organizationA = Organization::factory()->create(['name' => 'Alpha Org', 'slug' => 'alpha-org']);
    $organizationB = Organization::factory()->create(['name' => 'Beta Org', 'slug' => 'beta-org']);

    Membership::factory()->for($organizationA)->for($userA)->owner()->create();
    Membership::factory()->for($organizationB)->for($userB)->owner()->create();

    $this->actingAs($userA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Alpha Org')
        ->assertDontSee('Beta Org')
        ->assertDontSee('beta-org');
});

test('the organization list is scoped through the user memberships relationship', function () {
    $user = User::factory()->create();

    $organization = Organization::factory()->create();
    Membership::factory()->for($organization)->for($user)->owner()->create();

    Organization::factory()->count(3)->create();
    Membership::factory()->count(2)->create();

    $memberships = Livewire::actingAs($user)
        ->test('pages::organizations.list')
        ->instance()
        ->memberships();

    expect($memberships)->toHaveCount(1)
        ->and($memberships->first()->user_id)->toBe($user->id)
        ->and($memberships->first()->organization_id)->toBe($organization->id);
});

test('organization links on the dashboard use slug based routes', function () {
    $user = User::factory()->create();

    $organization = Organization::factory()->create(['slug' => 'acme-agency']);
    Membership::factory()->for($organization)->for($user)->owner()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('/o/acme-agency', escape: false)
        ->assertDontSee('/o/'.$organization->id, escape: false);
});

test('a user cannot reach another user\'s organization by typing its url', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $organizationB = Organization::factory()->create(['slug' => 'beta-org']);
    Membership::factory()->for($organizationB)->for($userB)->owner()->create();

    $this->actingAs($userA)
        ->get(route('organizations.dashboard', $organizationB))
        ->assertForbidden();

    $this->actingAs($userA)->get('/o/beta-org')->assertForbidden();
    $this->actingAs($userA)->get('/o/beta-org/members')->assertForbidden();
    $this->actingAs($userA)->get('/o/beta-org/settings')->assertForbidden();
});
