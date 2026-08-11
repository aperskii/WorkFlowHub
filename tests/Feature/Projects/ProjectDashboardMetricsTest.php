<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use Livewire\Livewire;

test('the organization dashboard reports real project counts', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->active()->count(2)->create();
    Project::factory()->for($organization)->planning()->create();
    Project::factory()->for($organization)->archived()->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization]);

    expect($component->instance()->projectCount())->toBe(4)
        ->and($component->instance()->activeProjectCount())->toBe(2);
});

test('dashboard project counts ignore other organizations', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->active()->create();
    Project::factory()->for($other)->active()->count(4)->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization]);

    expect($component->instance()->projectCount())->toBe(1)
        ->and($component->instance()->activeProjectCount())->toBe(1);
});

test('the dashboard shows an empty state when there are no projects', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->assertSee('Recent projects')
        ->assertSee('No projects yet.');
});

test('the dashboard lists recent projects with slug based links', function () {
    $organization = Organization::factory()->create(['slug' => 'acme-agency']);
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    $project = Project::factory()->for($organization)->active()->create([
        'name' => 'Website Redesign',
        'slug' => 'website-redesign',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->assertSee('Website Redesign')
        ->assertSee('/o/acme-agency/projects/website-redesign', escape: false)
        ->assertDontSee("/o/acme-agency/projects/{$project->id}", escape: false);
});

test('the dashboard shows at most six recent projects', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->count(9)->create();

    $component = Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization]);

    expect($component->instance()->recentProjects())->toHaveCount(6)
        ->and($component->instance()->projectCount())->toBe(9);
});

test('recent projects never include another organization\'s projects', function () {
    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Project::factory()->for($organization)->create(['name' => 'Ours']);
    Project::factory()->for($other)->create(['name' => 'Theirs']);

    Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->assertSee('Ours')
        ->assertDontSee('Theirs');
});

test('the dashboard keeps the member count and shows the viewer\'s role', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    memberWithRole($organization, OrganizationRole::Employee);

    $component = Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->assertSee('Members')
        ->assertSee(OrganizationRole::Owner->label());

    expect($component->instance()->memberCount())->toBe(2);
});

test('the owner count is no longer presented as a dashboard metric', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->assertDontSee('Owners');
});

test('the dashboard shows the documented task metrics and nothing beyond them', function () {
    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::organizations.dashboard', ['organization' => $organization])
        ->assertSee('Open tasks')
        ->assertSee('Completed tasks')
        ->assertDontSee('Tracked hours')
        ->assertDontSee('Recent activity')
        ->assertDontSee('Clients');
});
