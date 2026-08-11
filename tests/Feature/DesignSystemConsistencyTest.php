<?php

use App\Enums\OrganizationRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Shared surface
|--------------------------------------------------------------------------
|
| Every section in the product is the x-panel component, which renders the
| .wfh-panel surface. A page that reaches for a bare flux:card instead gets
| different corners, different padding, and — most visibly — a translucent
| ground in dark mode, so the check is that no page ships that chrome.
|
*/

/**
 * The class Flux emits on a card, which no application surface should use.
 */
const FLUX_CARD_CHROME = '[:where(&amp;)]:rounded-xl';

test('every signed-in surface is built from the shared panel', function (string $route) {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    Project::factory()->for($organization)->create(['slug' => 'a-project']);

    $url = str_contains($route, '{organization}')
        ? str_replace('{organization}', $organization->slug, $route)
        : $route;

    $this->actingAs($owner)
        ->get($url)
        ->assertOk()
        ->assertSee('wfh-panel', escape: false)
        ->assertDontSee(FLUX_CARD_CHROME, escape: false);
})->with([
    'organization dashboard' => '/o/{organization}',
    'projects index' => '/o/{organization}/projects',
    'create project' => '/o/{organization}/projects/new',
    'project' => '/o/{organization}/projects/a-project',
    'members' => '/o/{organization}/members',
    'organization settings' => '/o/{organization}/settings',
    'create organization' => '/organizations/create',
    'account profile' => '/settings/profile',
    'account security' => '/settings/security',
    'account appearance' => '/settings/appearance',
]);

test('the landing page a signed-out visitor sees uses the same panel', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('wfh-panel', escape: false)
        ->assertDontSee(FLUX_CARD_CHROME, escape: false);
});

test('the invitation page a guest lands on uses the same panel', function () {
    $organization = Organization::factory()->create();
    $inviter = memberWithRole($organization, OrganizationRole::Owner);

    $token = Str::random(48);

    $organization->invitations()->create([
        'email' => 'invitee@example.com',
        'role' => OrganizationRole::Manager,
        'token_hash' => Invitation::hashToken($token),
        'invited_by_user_id' => $inviter->id,
        'expires_at' => now()->addWeek(),
    ]);

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertSee('wfh-panel', escape: false)
        ->assertDontSee(FLUX_CARD_CHROME, escape: false);
});

/*
|--------------------------------------------------------------------------
| In-flight state
|--------------------------------------------------------------------------
|
| A mutating control that stays clickable while its request is in flight can
| be fired twice. For the destructive ones the second call lands after the
| record is gone, which surfaces as an error page rather than as a no-op.
|
*/

test('every mutating control is disabled while its request is in flight', function (string $route, array $targets) {
    $organization = Organization::factory()->create(['name' => 'Acme Agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    // A second member, so the removal control is rendered at all.
    memberWithRole($organization, OrganizationRole::Employee);

    Project::factory()->for($organization)->create(['slug' => 'a-project']);

    $response = $this->actingAs($owner)
        ->get(str_replace('{organization}', $organization->slug, $route))
        ->assertOk();

    foreach ($targets as $target) {
        $response->assertSee('wire:target="'.$target.'"', escape: false);
    }
})->with([
    'members' => ['/o/{organization}/members', ['sendInvitation', 'removeMember', 'revokeInvitation']],
    'organization settings' => ['/o/{organization}/settings', ['updateOrganization', 'deleteOrganization']],
    'project' => ['/o/{organization}/projects/a-project', ['archiveProject', 'saveTask', 'changeStatus']],
    'create project' => ['/o/{organization}/projects/new', ['createProject']],
    'create organization' => ['/organizations/create', ['createOrganization']],
]);

test('the account surfaces guard their mutating controls too', function (string $route, string $target) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get($route)
        ->assertOk()
        ->assertSee('wire:target="'.$target.'"', escape: false);
})->with([
    'profile' => ['/settings/profile', 'updateProfileInformation'],
    'password' => ['/settings/security', 'updatePassword'],
    'account deletion' => ['/settings/profile', 'deleteUser'],
]);

test('a per-row control guards only its own record', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $colleague = memberWithRole($organization, OrganizationRole::Employee);

    $project = Project::factory()->for($organization)->create(['slug' => 'a-project']);
    $first = Task::factory()->for($project)->todo()->create();
    $second = Task::factory()->for($project)->todo()->create();

    // Each row targets its own identifier, so changing one task's status never
    // freezes the controls on every other row.
    $this->actingAs($owner)
        ->get('/o/'.$organization->slug.'/projects/a-project')
        ->assertOk()
        ->assertSee('wire:target="changeStatus('.$first->id.')"', escape: false)
        ->assertSee('wire:target="changeStatus('.$second->id.')"', escape: false);

    $ownerMembership = $owner->membershipFor($organization);
    $colleagueMembership = $colleague->membershipFor($organization);

    $this->actingAs($owner)
        ->get('/o/'.$organization->slug.'/members')
        ->assertOk()
        ->assertSee('wire:target="updateRole('.$ownerMembership->id.')"', escape: false)
        ->assertSee('wire:target="updateRole('.$colleagueMembership->id.')"', escape: false);
});

/*
|--------------------------------------------------------------------------
| Dashboard information architecture
|--------------------------------------------------------------------------
*/

test('the dashboard states each organization figure once', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    // The member and project counts live in the metric row. A second summary
    // panel repeating them was removed.
    $this->actingAs($owner)
        ->get('/o/'.$organization->slug)
        ->assertOk()
        ->assertDontSee('data-test="organization-summary"', escape: false)
        ->assertSee('data-test="organization-member-count"', escape: false);
});

test('removing the summary panel did not cost anyone access to members', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);

    // The metric row still links to the members page for every role...
    $this->actingAs($member)
        ->get('/o/'.$organization->slug)
        ->assertOk()
        ->assertSee(route('organizations.members', $organization), escape: false);

    // ...and that page is genuinely reachable, not merely linked.
    $this->actingAs($member)
        ->get(route('organizations.members', $organization))
        ->assertOk();
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('a mutating control is never the authorization boundary', function () {
    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);

    // The employee sees no create control...
    $this->actingAs($employee)
        ->get('/o/'.$organization->slug)
        ->assertOk()
        ->assertDontSee('data-test="dashboard-new-project"', escape: false);

    // ...and reaching the page directly is still refused server-side.
    $this->actingAs($employee)
        ->get('/o/'.$organization->slug.'/projects/new')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Responsive
|--------------------------------------------------------------------------
*/

test('panel headers stack before the sm breakpoint', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    Project::factory()->for($organization)->create(['slug' => 'a-project']);

    // A fixed-width filter beside the title would otherwise crush the heading
    // to an ellipsis on a phone.
    $this->actingAs($owner)
        ->get('/o/'.$organization->slug.'/projects/a-project')
        ->assertOk()
        ->assertSee('sm:flex-row sm:items-start sm:justify-between', escape: false)
        ->assertSee('w-full sm:w-40', escape: false);
});

test('the organization metric row only goes five across at xl', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    // Five cards inside a 1024px viewport leave roughly 130px each, which
    // truncates the labels. The row steps 1 -> 2 -> 3 -> 5 instead.
    $this->actingAs($owner)
        ->get('/o/'.$organization->slug)
        ->assertOk()
        ->assertSee('grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5', escape: false)
        ->assertDontSee('lg:grid-cols-5', escape: false);
});

test('loading indicators carry text for a screen reader', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    Project::factory()->for($organization)->create(['slug' => 'a-project']);

    $this->actingAs($owner)
        ->get('/o/'.$organization->slug.'/projects')
        ->assertOk()
        ->assertSee('Loading projects');

    $this->actingAs($owner)
        ->get('/o/'.$organization->slug.'/projects/a-project')
        ->assertOk()
        ->assertSee('Loading tasks');
});

test('the viewer role is stated once on the dashboard', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner)
        ->get('/o/'.$organization->slug)
        ->assertOk()
        ->assertSee('data-test="organization-role"', escape: false)
        ->assertDontSee('Your role');
});

test('no surface invents a figure the domain cannot supply', function (string $route) {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    Project::factory()->for($organization)->create(['slug' => 'a-project']);

    $response = $this->actingAs($owner)
        ->get(str_replace('{organization}', $organization->slug, $route))
        ->assertOk();

    foreach (['% this week', '% this month', 'Tracked hours', 'vs last'] as $fabrication) {
        $response->assertDontSee($fabrication);
    }
})->with([
    'organization dashboard' => '/o/{organization}',
    'projects index' => '/o/{organization}/projects',
    'project' => '/o/{organization}/projects/a-project',
]);
