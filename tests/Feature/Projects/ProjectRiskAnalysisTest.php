<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskPriority;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * No test may reach the real Anthropic API: it costs money, needs a key that
 * does not exist in CI, and would make the suite non-deterministic. Stray
 * requests are turned into failures rather than silently passed through.
 */
beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('services.anthropic.key', 'test-key');
});

/**
 * A successful Messages API response carrying the given assessment.
 *
 * @return array<string, mixed>
 */
function riskApiResponse(string $assessment): array
{
    return [
        'id' => 'msg_01XyZ',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-4-5',
        'content' => [['type' => 'text', 'text' => $assessment]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 214, 'output_tokens' => 88],
    ];
}

/**
 * Open a project page as the given user.
 */
function riskPageAs(User $user, Organization $organization, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.show', [
        'organization' => $organization,
        'project' => $project,
    ]);
}

/*
|--------------------------------------------------------------------------
| On demand only
|--------------------------------------------------------------------------
*/

test('no analysis is requested when the page is merely opened', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->active()->create();

    Task::factory()->count(3)->for($project)->create();

    riskPageAs($owner, $organization, $project)
        ->assertSee('data-test="analyze-risk-button"', escape: false)
        ->assertDontSee('data-test="risk-assessment"', escape: false);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

test('an assessment is fetched on click and rendered on the page', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(riskApiResponse(
            'This project looks at risk. Six of its twenty tasks are overdue and only 30% are complete.'
        )),
    ]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->active()->create();

    riskPageAs($owner, $organization, $project)
        ->call('analyzeRisk')
        ->assertHasNoErrors()
        ->assertSet('riskError', null)
        ->assertSee('Six of its twenty tasks are overdue')
        ->assertSee('data-test="risk-assessment"', escape: false);

    Http::assertSentCount(1);
});

test('the request is addressed and authenticated the way the Messages API expects', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy.'))]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    riskPageAs($owner, $organization, $project)->call('analyzeRisk');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->method() === 'POST'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['model'] === 'claude-haiku-4-5'
            && is_int($request['max_tokens'])
            && is_string($request['system'])
            && $request['messages'][0]['role'] === 'user';
    });
});

test('the model is the cheap one, and is configurable without touching code', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy.'))]);

    config()->set('services.anthropic.model', 'claude-haiku-4-5');

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    riskPageAs($owner, $organization, $project)->call('analyzeRisk');

    Http::assertSent(fn (Request $request) => $request['model'] === 'claude-haiku-4-5');
});

/*
|--------------------------------------------------------------------------
| Data minimization
|--------------------------------------------------------------------------
*/

test('only aggregate counts are sent, and every count is correct', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy.'))]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->active()->create(['name' => 'Website Redesign']);

    // Two done, two overdue, one unassigned and open, one urgent due in three days.
    Task::factory()->count(2)->for($project)->done()->create();
    Task::factory()->count(2)->for($project)->todo()->assignedTo($owner)
        ->dueOn(today()->subDays(4)->toDateString())->create();
    Task::factory()->for($project)->todo()->create(['assigned_to_user_id' => null]);
    Task::factory()->for($project)->inProgress()->assignedTo($owner)
        ->priority(TaskPriority::Urgent)->dueOn(today()->addDays(3)->toDateString())->create();

    riskPageAs($owner, $organization, $project)->call('analyzeRisk');

    Http::assertSent(function (Request $request) {
        $snapshot = json_decode($request['messages'][0]['content'], associative: true);

        expect($snapshot)->toBe([
            'project_name' => 'Website Redesign',
            'project_status' => 'active',
            'project_age_days' => 0,
            'total_tasks' => 6,
            'completed_tasks' => 2,
            'open_tasks' => 4,
            'completion_percentage' => 33,
            'overdue_tasks' => 2,
            'unassigned_open_tasks' => 1,
            'high_priority_due_within_7_days' => 1,
        ]);

        return true;
    });
});

test('task titles, member identities, and the project description never leave the application', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy.'))]);

    $organization = Organization::factory()->create();

    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $owner->update(['name' => 'Marguerite Delacroix', 'email' => 'marguerite@acme-client.test']);

    $project = Project::factory()->for($organization)->active()->create([
        'name' => 'Website Redesign',
        'description' => 'Confidential: rebuild the marketing site before the Zephyr acquisition closes.',
    ]);

    Task::factory()->for($project)->assignedTo($owner)->create([
        'title' => 'Rotate the production database credentials',
        'description' => 'The old password is hunter2 and is shared in the team drive.',
    ]);

    riskPageAs($owner, $organization, $project)->call('analyzeRisk');

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        foreach ([
            'Rotate the production database credentials',
            'hunter2',
            'Marguerite Delacroix',
            'marguerite@acme-client.test',
            'Zephyr acquisition',
        ] as $secret) {
            expect($body)->not->toContain($secret);
        }

        // The project name is the one identifier that is sent, deliberately, so
        // the assessment can name the project it is talking about.
        expect($body)->toContain('Website Redesign');

        return true;
    });
});

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

test('a non-member cannot trigger an analysis', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    memberWithRole($organization, OrganizationRole::Owner);
    $outsider = User::factory()->create();
    $project = Project::factory()->for($organization)->create();

    riskPageAs($outsider, $organization, $project)->assertForbidden();

    Http::assertNothingSent();
});

test('a member of another organization cannot analyze this project', function () {
    Http::fake();

    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $ownerOfB = memberWithRole($organizationB, OrganizationRole::Owner);
    $projectInA = Project::factory()->for($organizationA)->create();

    Livewire::actingAs($ownerOfB)
        ->test('pages::projects.show', [
            'organization' => $organizationA,
            'project' => $projectInA,
        ])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a manager or owner can analyze a project', function (OrganizationRole $role) {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy enough.'))]);

    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);
    $project = Project::factory()->for($organization)->create();

    riskPageAs($member, $organization, $project)
        ->call('analyzeRisk')
        ->assertSee('Healthy enough.');
})->with([
    'owner' => OrganizationRole::Owner,
    'manager' => OrganizationRole::Manager,
]);

test('an employee cannot trigger an analysis, and is never billed for one', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    $employee = memberWithRole($organization, OrganizationRole::Employee);
    $project = Project::factory()->for($organization)->create();

    // An employee may still read the project; only the paid action is refused.
    riskPageAs($employee, $organization, $project)
        ->assertOk()
        ->call('analyzeRisk')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the analyze control is hidden from members who may not spend', function (OrganizationRole $role, bool $visible) {
    Http::fake();

    $organization = Organization::factory()->create();
    $member = memberWithRole($organization, $role);
    $project = Project::factory()->for($organization)->create();

    $component = riskPageAs($member, $organization, $project);

    $visible
        ? $component->assertSee('data-test="analyze-risk-button"', escape: false)
        : $component->assertDontSee('data-test="analyze-risk-button"', escape: false);
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'manager' => [OrganizationRole::Manager, true],
    'employee' => [OrganizationRole::Employee, false],
]);

test('the analysis re-authorizes when a member is demoted after mounting', function () {
    Http::fake();

    $organization = Organization::factory()->create();
    $manager = memberWithRole($organization, OrganizationRole::Manager);
    memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    $component = riskPageAs($manager, $organization, $project);

    $manager->membershipFor($organization)->update(['role' => OrganizationRole::Employee]);

    $component->call('analyzeRisk')->assertForbidden();

    Http::assertNothingSent();
});

test('the analysis re-authorizes at action time rather than trusting mount', function () {
    Http::fake();

    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();

    $owner = memberWithRole($organizationA, OrganizationRole::Owner);
    $project = Project::factory()->for($organizationA)->create();

    $component = riskPageAs($owner, $organizationA, $project);

    // The project leaves the organization after the component mounted.
    $project->forceFill(['organization_id' => $organizationB->id])->save();

    expect(fn () => $component->call('analyzeRisk'))
        ->toThrow(ModelNotFoundException::class);

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Rate limiting
|--------------------------------------------------------------------------
*/

test('a member is cut off after five analyses in a minute', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy.'))]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    $component = riskPageAs($owner, $organization, $project);

    foreach (range(1, 5) as $attempt) {
        $component->call('analyzeRisk')->assertSet('riskError', null);
    }

    $component->call('analyzeRisk')
        ->assertSet('riskAssessment', null)
        ->assertSee('Try again in')
        ->assertSee('data-test="risk-error"', escape: false);

    // The throttled press must not have cost an API call.
    Http::assertSentCount(5);
});

test('the throttle is scoped to the member, so one member cannot block another', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy.'))]);

    $organization = Organization::factory()->create();
    $first = memberWithRole($organization, OrganizationRole::Owner);
    $second = memberWithRole($organization, OrganizationRole::Manager);
    $project = Project::factory()->for($organization)->create();

    $exhausted = riskPageAs($first, $organization, $project);

    foreach (range(1, 6) as $attempt) {
        $exhausted->call('analyzeRisk');
    }

    riskPageAs($second, $organization, $project)
        ->call('analyzeRisk')
        ->assertSet('riskError', null)
        ->assertSee('Healthy.');
});

test('the throttle follows the member across projects in the same organization', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(riskApiResponse('Healthy.'))]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $first = Project::factory()->for($organization)->create();
    $second = Project::factory()->for($organization)->create();

    $onFirst = riskPageAs($owner, $organization, $first);

    foreach (range(1, 5) as $attempt) {
        $onFirst->call('analyzeRisk');
    }

    riskPageAs($owner, $organization, $second)
        ->call('analyzeRisk')
        ->assertSet('riskAssessment', null)
        ->assertSee('Try again in');

    Http::assertSentCount(5);
});

/*
|--------------------------------------------------------------------------
| Failure handling
|--------------------------------------------------------------------------
*/

test('an API failure is reported plainly and never breaks the page', function (int $status) {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'Internal detail that must not surface.'],
        ], $status),
    ]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create(['name' => 'Website Redesign']);

    riskPageAs($owner, $organization, $project)
        ->call('analyzeRisk')
        ->assertOk()
        ->assertSet('riskAssessment', null)
        ->assertSee('We could not analyze this project right now.')
        // The page itself keeps working and stays usable.
        ->assertSee('Website Redesign')
        ->assertSee('data-test="analyze-risk-button"', escape: false)
        // Nothing from the API error reaches the user.
        ->assertDontSee('overloaded_error')
        ->assertDontSee('Internal detail that must not surface.');
})->with([
    'bad request' => 400,
    'unauthorized' => 401,
    'rate limited by Anthropic' => 429,
    'server error' => 500,
    'overloaded' => 529,
]);

test('a network failure or timeout is reported plainly', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    riskPageAs($owner, $organization, $project)
        ->call('analyzeRisk')
        ->assertOk()
        ->assertSet('riskAssessment', null)
        ->assertSee('We could not analyze this project right now.')
        ->assertDontSee('cURL error 28');
});

test('a malformed or empty response is treated as a failure', function (array $payload) {
    Http::fake(['api.anthropic.com/*' => Http::response($payload)]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    riskPageAs($owner, $organization, $project)
        ->call('analyzeRisk')
        ->assertOk()
        ->assertSet('riskAssessment', null)
        ->assertSee('We could not analyze this project right now.');
})->with([
    'no content key' => [['id' => 'msg_01', 'type' => 'message']],
    'empty content' => [['content' => []]],
    'no text block' => [['content' => [['type' => 'thinking', 'thinking' => '...']]]],
    'blank text' => [['content' => [['type' => 'text', 'text' => '   ']]]],
]);

test('a refusal is treated as a failure rather than shown as an assessment', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
        ]),
    ]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    riskPageAs($owner, $organization, $project)
        ->call('analyzeRisk')
        ->assertSet('riskAssessment', null)
        ->assertSee('We could not analyze this project right now.')
        ->assertDontSee('refusal');
});

test('with no API key configured the feature reports unavailable instead of calling out', function () {
    Http::fake();

    config()->set('services.anthropic.key', null);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    riskPageAs($owner, $organization, $project)
        ->call('analyzeRisk')
        ->assertOk()
        ->assertSee('We could not analyze this project right now.');

    Http::assertNothingSent();
});

test('a stale assessment is cleared before a new attempt', function () {
    // A sequence, not two Http::fake() calls: a second fake appends a stub
    // rather than replacing the first, so the original response would win again.
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(riskApiResponse('First reading of the project.'))
            ->pushStatus(500),
    ]);

    $organization = Organization::factory()->create();
    $owner = memberWithRole($organization, OrganizationRole::Owner);
    $project = Project::factory()->for($organization)->create();

    $component = riskPageAs($owner, $organization, $project)
        ->call('analyzeRisk')
        ->assertSee('First reading of the project.');

    $component->call('analyzeRisk')
        ->assertSet('riskAssessment', null)
        ->assertDontSee('First reading of the project.')
        ->assertSee('We could not analyze this project right now.');
});
