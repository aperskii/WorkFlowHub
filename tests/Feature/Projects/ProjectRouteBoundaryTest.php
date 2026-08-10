<?php

use App\Enums\OrganizationRole;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Route;

test('project routes are bound by slug rather than id', function () {
    $organization = Organization::factory()->create(['slug' => 'acme-agency']);
    $project = Project::factory()->for($organization)->create(['slug' => 'website-redesign']);

    expect(route('organizations.projects.index', $organization, absolute: false))
        ->toBe('/o/acme-agency/projects')
        ->and(route('organizations.projects.create', $organization, absolute: false))
        ->toBe('/o/acme-agency/projects/new')
        ->and(route('organizations.projects.show', [$organization, $project], absolute: false))
        ->toBe('/o/acme-agency/projects/website-redesign');
});

test('a guest is redirected to login', function (string $routeName) {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();

    $parameters = $routeName === 'organizations.projects.show'
        ? [$organization, $project]
        : [$organization];

    $this->get(route($routeName, $parameters))->assertRedirect(route('login'));
})->with([
    'index' => 'organizations.projects.index',
    'create' => 'organizations.projects.create',
    'show' => 'organizations.projects.show',
]);

test('an unverified user is redirected to email verification', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->unverified()->create();
    Membership::factory()->for($organization)->for($user)->owner()->create();

    $this->actingAs($user)
        ->get(route('organizations.projects.index', $organization))
        ->assertRedirect(route('verification.notice'));
});

test('a member of any role can open the project index and a project', function (OrganizationRole $role) {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);
    $project = Project::factory()->for($organization)->create();

    $this->actingAs($member)
        ->get(route('organizations.projects.index', $organization))
        ->assertOk();

    $this->actingAs($member)
        ->get(route('organizations.projects.show', [$organization, $project]))
        ->assertOk()
        ->assertSee($project->name);
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
    'employee' => OrganizationRole::Employee,
]);

test('a non-member cannot open the project index or a project', function () {
    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('organizations.projects.index', $organization))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->get(route('organizations.projects.show', [$organization, $project]))
        ->assertForbidden();
});

test('only owners and managers can open the create project page', function (OrganizationRole $role, bool $allowed) {
    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);

    $response = $this->actingAs($member)->get(route('organizations.projects.create', $organization));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'manager' => [OrganizationRole::Manager, true],
    'employee' => [OrganizationRole::Employee, false],
]);

/*
|--------------------------------------------------------------------------
| Cross-tenant isolation through scoped route bindings
|--------------------------------------------------------------------------
*/

test('a project slug from another organization returns 404', function () {
    $organizationA = Organization::factory()->create(['slug' => 'org-a']);
    $organizationB = Organization::factory()->create(['slug' => 'org-b']);

    $ownerOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $projectInB = Project::factory()->for($organizationB)->create(['slug' => 'secret-project']);

    $this->actingAs($ownerOfA)
        ->get("/o/org-a/projects/{$projectInB->slug}")
        ->assertNotFound();
});

test('a member of one organization cannot reach another organization\'s project index', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($memberOfA)
        ->get(route('organizations.projects.index', $organizationB))
        ->assertForbidden();
});

test('an identical project slug in two organizations resolves to the correct project', function () {
    $organizationA = Organization::factory()->create(['slug' => 'org-a']);
    $organizationB = Organization::factory()->create(['slug' => 'org-b']);

    $projectInA = Project::factory()->for($organizationA)->create([
        'name' => 'Alpha Redesign',
        'slug' => 'website-redesign',
    ]);

    $projectInB = Project::factory()->for($organizationB)->create([
        'name' => 'Beta Redesign',
        'slug' => 'website-redesign',
    ]);

    $memberOfA = memberWithRole($organizationA, OrganizationRole::Owner);
    $memberOfB = memberWithRole($organizationB, OrganizationRole::Owner);

    $this->actingAs($memberOfA)
        ->get('/o/org-a/projects/website-redesign')
        ->assertOk()
        ->assertSee($projectInA->name)
        ->assertDontSee($projectInB->name);

    $this->actingAs($memberOfB)
        ->get('/o/org-b/projects/website-redesign')
        ->assertOk()
        ->assertSee($projectInB->name)
        ->assertDontSee($projectInA->name);
});

test('an unknown project slug returns 404', function () {
    $organization = Organization::factory()->create(['slug' => 'acme-agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner)
        ->get('/o/acme-agency/projects/does-not-exist')
        ->assertNotFound();
});

test('an unknown organization slug returns 404 for project routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/o/nope/projects')->assertNotFound();
    $this->actingAs($user)->get('/o/nope/projects/new')->assertNotFound();
    $this->actingAs($user)->get('/o/nope/projects/anything')->assertNotFound();
});

test('the new project route is not swallowed by the project slug route', function () {
    $organization = Organization::factory()->create(['slug' => 'acme-agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $this->actingAs($owner)
        ->get('/o/acme-agency/projects/new')
        ->assertOk()
        ->assertSee('New project');
});

test('there is no delete route for projects', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->getName(), 'projects'));

    expect($routes->pluck('action.as')->filter()->values()->all())
        ->toEqualCanonicalizing([
            'organizations.projects.index',
            'organizations.projects.create',
            'organizations.projects.show',
        ]);
});
