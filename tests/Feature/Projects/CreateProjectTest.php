<?php

use App\Actions\Projects\CreateProject;
use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->action = new CreateProject;
    $this->organization = Organization::factory()->create();
});

test('creating a project persists it against the organization', function () {
    $project = $this->action->handle($this->organization, 'Website Redesign');

    $this->assertModelExists($project);

    expect($project->name)->toBe('Website Redesign')
        ->and($project->organization_id)->toBe($this->organization->id)
        ->and($this->organization->projects()->count())->toBe(1);
});

test('a new project defaults to the planning status', function () {
    $project = $this->action->handle($this->organization, 'Website Redesign');

    expect($project->status)->toBe(ProjectStatus::Planning);
});

test('a project can be created with an explicit status', function () {
    $project = $this->action->handle($this->organization, 'Website Redesign', null, ProjectStatus::Active);

    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});

test('the description is optional and stored when given', function () {
    $without = $this->action->handle($this->organization, 'No Description');
    $with = $this->action->handle($this->organization, 'With Description', 'Rebuild the marketing site.');

    expect($without->description)->toBeNull()
        ->and($with->description)->toBe('Rebuild the marketing site.');
});

test('the slug is generated from the project name and normalized to lowercase', function () {
    $project = $this->action->handle($this->organization, 'Website REDESIGN 2026');

    expect($project->slug)->toBe('website-redesign-2026')
        ->and($project->slug)->toBe(mb_strtolower($project->slug));
});

test('slug collisions inside one organization are resolved with a numeric suffix', function () {
    $first = $this->action->handle($this->organization, 'Website Redesign');
    $second = $this->action->handle($this->organization, 'Website Redesign');
    $third = $this->action->handle($this->organization, 'website redesign');

    expect($first->slug)->toBe('website-redesign')
        ->and($second->slug)->toBe('website-redesign-2')
        ->and($third->slug)->toBe('website-redesign-3');
});

test('the same slug is allowed in a different organization', function () {
    $other = Organization::factory()->create();

    $first = $this->action->handle($this->organization, 'Website Redesign');
    $second = $this->action->handle($other, 'Website Redesign');

    expect($first->slug)->toBe('website-redesign')
        ->and($second->slug)->toBe('website-redesign')
        ->and($first->organization_id)->not->toBe($second->organization_id);
});

test('a name that slugifies to nothing still produces a usable slug', function () {
    $project = $this->action->handle($this->organization, '###');

    expect($project->slug)->toBe('project');
});

test('the project name is required', function () {
    expect(fn () => $this->action->handle($this->organization, ''))
        ->toThrow(ValidationException::class);

    expect(Project::count())->toBe(0);
});

test('the project name may not exceed 255 characters', function () {
    expect(fn () => $this->action->handle($this->organization, str_repeat('a', 256)))
        ->toThrow(ValidationException::class);

    expect(Project::count())->toBe(0);
});

test('the project description may not exceed 2000 characters', function () {
    expect(fn () => $this->action->handle($this->organization, 'Valid Name', str_repeat('a', 2001)))
        ->toThrow(ValidationException::class);

    expect(Project::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Schema integrity
|--------------------------------------------------------------------------
*/

test('the database rejects a duplicate slug within one organization', function () {
    $this->organization->projects()->create([
        'name' => 'Website Redesign',
        'slug' => 'website-redesign',
        'status' => ProjectStatus::Planning,
    ]);

    expect($this->organization->projects()->count())->toBe(1);

    expect(fn () => $this->organization->projects()->create([
        'name' => 'Another Project',
        'slug' => 'website-redesign',
        'status' => ProjectStatus::Planning,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('deleting an organization cascades its projects', function () {
    Project::factory()->for($this->organization)->count(3)->create();
    $survivor = Project::factory()->create();

    expect(Project::count())->toBe(4);

    $this->organization->delete();

    expect(Project::count())->toBe(1);
    $this->assertModelExists($survivor);
});

test('the status column is cast to the project status enum', function () {
    $project = Project::factory()->for($this->organization)->active()->create();

    expect($project->refresh()->status)->toBe(ProjectStatus::Active);
});

test('every factory status state persists correctly', function (string $state, ProjectStatus $expected) {
    $project = Project::factory()->for($this->organization)->{$state}()->create();

    expect($project->refresh()->status)->toBe($expected);
})->with([
    'planning' => ['planning', ProjectStatus::Planning],
    'active' => ['active', ProjectStatus::Active],
    'onHold' => ['onHold', ProjectStatus::OnHold],
    'completed' => ['completed', ProjectStatus::Completed],
    'archived' => ['archived', ProjectStatus::Archived],
]);

test('the active scope only returns active projects', function () {
    Project::factory()->for($this->organization)->active()->count(2)->create();
    Project::factory()->for($this->organization)->planning()->create();
    Project::factory()->for($this->organization)->archived()->create();

    expect($this->organization->projects()->active()->count())->toBe(2)
        ->and($this->organization->projects()->archived()->count())->toBe(1)
        ->and($this->organization->projects()->count())->toBe(4);
});

test('a project belongs to its organization', function () {
    $project = Project::factory()->for($this->organization)->create();

    expect($project->organization->id)->toBe($this->organization->id);
});

test('projects are never shared between organizations', function () {
    $other = Organization::factory()->create();

    Project::factory()->for($this->organization)->count(2)->create();
    Project::factory()->for($other)->count(3)->create();

    expect($this->organization->projects()->count())->toBe(2)
        ->and($other->projects()->count())->toBe(3);
});

test('creating a project does not create any membership', function () {
    $user = User::factory()->create();

    $this->action->handle($this->organization, 'Website Redesign');

    expect($user->memberships()->count())->toBe(0)
        ->and($this->organization->memberships()->count())->toBe(0);
});
