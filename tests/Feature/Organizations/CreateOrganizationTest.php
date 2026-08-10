<?php

use App\Actions\Organizations\CreateOrganization;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->action = new CreateOrganization;
});

test('creating an organization persists the organization', function () {
    $user = User::factory()->create();

    $organization = $this->action->handle($user, 'Acme Agency');

    $this->assertModelExists($organization);
    expect($organization->name)->toBe('Acme Agency');
});

test('the creator automatically becomes an owner', function () {
    $user = User::factory()->create();

    $organization = $this->action->handle($user, 'Acme Agency');

    $membership = $user->membershipFor($organization);

    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe(OrganizationRole::Owner)
        ->and($organization->owners()->count())->toBe(1)
        ->and($organization->hasMember($user))->toBeTrue();
});

test('creation is atomic so a failed membership insert leaves no organization', function () {
    $unsavedUser = User::factory()->make(['id' => 999_999]);

    expect(fn () => $this->action->handle($unsavedUser, 'Acme Agency'))
        ->toThrow(QueryException::class);

    expect(Organization::count())->toBe(0);
});

test('the slug is generated from the organization name', function () {
    $user = User::factory()->create();

    $organization = $this->action->handle($user, 'Acme Agency');

    expect($organization->slug)->toBe('acme-agency');
});

test('the generated slug is normalized to lowercase', function () {
    $user = User::factory()->create();

    $organization = $this->action->handle($user, 'ACME Agency GmbH');

    expect($organization->slug)->toBe('acme-agency-gmbh')
        ->and($organization->slug)->toBe(mb_strtolower($organization->slug));
});

test('slug collisions are resolved with a numeric suffix', function () {
    $user = User::factory()->create();

    $first = $this->action->handle($user, 'Acme Agency');
    $second = $this->action->handle($user, 'Acme Agency');
    $third = $this->action->handle($user, 'acme agency');

    expect($first->slug)->toBe('acme-agency')
        ->and($second->slug)->toBe('acme-agency-2')
        ->and($third->slug)->toBe('acme-agency-3');
});

test('a name that slugifies to nothing still produces a usable slug', function () {
    $user = User::factory()->create();

    $organization = $this->action->handle($user, '###');

    expect($organization->slug)->toBe('organization');
});

test('the organization name is required', function () {
    $user = User::factory()->create();

    expect(fn () => $this->action->handle($user, ''))
        ->toThrow(ValidationException::class);

    expect(Organization::count())->toBe(0);
});

test('the organization name may not exceed 255 characters', function () {
    $user = User::factory()->create();

    expect(fn () => $this->action->handle($user, str_repeat('a', 256)))
        ->toThrow(ValidationException::class);

    expect(Organization::count())->toBe(0);
});
