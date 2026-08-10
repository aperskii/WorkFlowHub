<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Register through the real HTTP endpoint.
 */
function registerUser(string $email = 'new-user@example.com'): TestResponse
{
    return test()->post(route('register.store'), [
        'name' => 'New User',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);
}

test('the auto verify setting is disabled by default', function () {
    expect(config('auth.auto_verify_new_users'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Local development: verification bypassed for new registrations
|--------------------------------------------------------------------------
*/

test('a locally registered user is marked as verified', function () {
    config(['auth.auto_verify_new_users' => true]);

    registerUser()->assertSessionHasNoErrors();

    $user = User::where('email', 'new-user@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('a locally registered user reaches the dashboard without verifying', function () {
    config(['auth.auto_verify_new_users' => true]);

    registerUser()->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('dashboard'))->assertOk();
});

test('a locally registered user is not sent a verification email', function () {
    config(['auth.auto_verify_new_users' => true]);

    Notification::fake();

    registerUser()->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('a locally registered user can reach organization features immediately', function () {
    config(['auth.auto_verify_new_users' => true]);

    registerUser()->assertSessionHasNoErrors();

    $this->get(route('organizations.create'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Production/staging: verification still required
|--------------------------------------------------------------------------
*/

test('a user registered without the bypass is unverified and cannot reach the dashboard', function () {
    config(['auth.auto_verify_new_users' => false]);

    registerUser()->assertSessionHasNoErrors();

    $user = User::where('email', 'new-user@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();

    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

test('a user registered without the bypass is still sent a verification email', function () {
    config(['auth.auto_verify_new_users' => false]);

    Notification::fake();

    registerUser()->assertSessionHasNoErrors();

    $user = User::where('email', 'new-user@example.com')->sole();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('the bypass never verifies an existing unverified user', function () {
    config(['auth.auto_verify_new_users' => true]);

    $existing = User::factory()->unverified()->create();

    registerUser()->assertSessionHasNoErrors();

    expect($existing->refresh()->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($existing)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

/*
|--------------------------------------------------------------------------
| The verification mechanism itself stays intact
|--------------------------------------------------------------------------
*/

test('the verified middleware still guards organization routes regardless of the setting', function (bool $bypass) {
    config(['auth.auto_verify_new_users' => $bypass]);

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('organizations.create'))
        ->assertRedirect(route('verification.notice'));
})->with([
    'bypass enabled' => true,
    'bypass disabled' => false,
]);

test('the user model still requires email verification', function () {
    config(['auth.auto_verify_new_users' => true]);

    expect(User::factory()->create())
        ->toBeInstanceOf(MustVerifyEmail::class);
});

test('the verification routes remain registered', function () {
    expect(Route::has('verification.notice'))->toBeTrue()
        ->and(Route::has('verification.verify'))->toBeTrue()
        ->and(Route::has('verification.send'))->toBeTrue();
});
