<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('the reset link request gives the same answer whether or not the email has an account', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'member+1@qa-tenant.test']);

    $this->post(route('password.email'), ['email' => 'nobody+404@qa-tenant.test'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('status', __('passwords.sent'));

    $this->post(route('password.email'), ['email' => 'Member+1@QA-tenant.test'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('password.request'))
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('a second reset link request within a minute says the same and sends nothing more', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('reset password email has no manage-notifications link — it is a transactional, unauthenticated email', function () {
    $user = User::factory()->create();

    $html = view('mail.password-reset', [
        'title' => 'Reset your password',
        'previewText' => 'Reset your password.',
        'user' => $user,
        'url' => 'https://example.com/reset',
    ])->render();

    expect($html)->not->toContain('Manage notifications');
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.store'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => strongPassword(),
            'password_confirmation' => strongPassword(),
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.store'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
    ]);

    $response->assertSessionHasErrors('email');
});

test('the reset page shows the form for a valid link and offers a new link for a used one', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => strtoupper($user->email)]))
        ->assertInertia(fn ($page) => $page->component('auth/ResetPassword')
            ->where('tokenValid', true)
            ->has('passwordRequirements'));

    $this->get(route('password.reset', ['token' => 'used-token', 'email' => $user->email]))
        ->assertInertia(fn ($page) => $page->where('tokenValid', false));

    $this->get(route('password.reset', ['token' => $token, 'email' => 'nobody@example.com']))
        ->assertInertia(fn ($page) => $page->where('tokenValid', false));
});

test('a reset for an unknown email reads as a bad link, not a missing user', function () {
    $this->post(route('password.store'), [
        'token' => 'any-token',
        'email' => 'nobody@example.com',
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
    ])->assertSessionHasErrors(['email' => __('passwords.token')]);
});

test('mismatched passwords get a plain message', function () {
    $user = User::factory()->create();

    $this->post(route('password.store'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => strongPassword(),
        'password_confirmation' => strongPassword().'x',
    ])->assertSessionHasErrors(['password' => __('auth.reset_password.mismatch')]);
});

test('a password reset accepts the email with capitals', function () {
    $user = User::factory()->create(['email' => 'member+1@qa-tenant.test']);

    $this->post(route('password.store'), [
        'token' => Password::broker()->createToken($user),
        'email' => 'Member+1@qa-tenant.test',
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
});
