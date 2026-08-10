<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Livewire\Livewire;

test('a user who owns no organization can still delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertModelMissing($user);
});

test('a member who is not a sole owner can delete their account', function () {
    $organization = Organization::factory()->create();

    Membership::factory()->for($organization)->owner()->create();
    $employeeMembership = Membership::factory()->for($organization)->employee()->create();
    $employee = $employeeMembership->user;

    $this->actingAs($employee);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertModelMissing($employee);
    $this->assertModelMissing($employeeMembership);
    expect($organization->owners()->count())->toBe(1);
});

test('the sole owner of an organization cannot delete their account', function () {
    $organization = Organization::factory()->create();
    $ownerMembership = Membership::factory()->for($organization)->owner()->create();
    $owner = $ownerMembership->user;

    $this->actingAs($owner);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('password');

    $this->assertModelExists($owner);
    $this->assertModelExists($ownerMembership);
    expect($organization->owners()->count())->toBe(1);
});

test('the sole owner remains authenticated after a blocked account deletion', function () {
    $organization = Organization::factory()->create();
    $owner = Membership::factory()->for($organization)->owner()->create()->user;

    $this->actingAs($owner);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('password');

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($owner->id);
});

test('an owner can delete their account once another owner exists', function () {
    $organization = Organization::factory()->create();

    $firstOwnerMembership = Membership::factory()->for($organization)->owner()->create();
    Membership::factory()->for($organization)->owner()->create();

    $owner = $firstOwnerMembership->user;

    $this->actingAs($owner);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    $this->assertModelMissing($owner);
    expect($organization->owners()->count())->toBe(1);
});

test('an incorrect password still blocks account deletion for an ordinary user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser')
        ->assertHasErrors('password');

    $this->assertModelExists($user);
});
