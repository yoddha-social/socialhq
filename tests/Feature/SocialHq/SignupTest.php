<?php

declare(strict_types=1);

use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Signup\AccessRequest;
use App\SocialHq\Signup\Mail\AccessApproved;
use App\SocialHq\Signup\Mail\AccessRequested;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config([
        'trypost.self_hosted' => true,
        'socialhq.signup.domains' => ['acme.test', 'gmail.com'],
        'socialhq.signup.emails' => ['freelancer@studio.test'],
        'socialhq.signup.account_owner' => null,
    ]);

    $this->account = Account::factory()->create();
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id, 'name' => 'Brand A']);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->otherWorkspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id, 'name' => 'Brand B']);
    $this->otherWorkspace->members()->attach($this->owner->id, membershipPivot('admin'));
});

function signUp(string $email): TestResponse
{
    return test()->post(route('register.store'), [
        'name' => 'Asha Rai',
        'email' => $email,
        'password' => strongPassword(),
        'locale' => 'en',
    ]);
}

function verifyEmailOf(User $user): void
{
    test()->actingAs($user)->get(URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    ));
}

test('a company email signs up into the team account with no workspace and waits', function () {
    Bus::fake([SendNotification::class]);

    signUp('asha@acme.test')->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();

    expect($user->account_id)->toBe($this->account->id)
        ->and($user->workspaces()->count())->toBe(0)
        ->and($user->current_workspace_id)->toBeNull()
        ->and(Account::query()->count())->toBe(1)
        ->and(AccessRequest::query()->where('user_id', $user->id)->value('status'))->toBe(AccessRequest::PENDING);

    $this->get(route('app.welcome'))->assertRedirect(route('socialhq.access.pending'));
    $this->get(route('socialhq.access.pending'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/AccessPending')->where('verified', false));

    Bus::assertNotDispatched(SendNotification::class);
});

test('admins hear about a sign-up once its email is confirmed, and only once', function () {
    Bus::fake([SendNotification::class]);
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();

    verifyEmailOf($user);
    verifyEmailOf($user->fresh());

    Bus::assertDispatchedTimes(SendNotification::class, 1);
    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($this->owner)
        && $job->mailable instanceof AccessRequested);
});

test('a single listed email may sign up, other emails and public domains may not', function (string $email, bool $allowed) {
    $response = signUp($email);

    $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors(['email' => __('socialhq.signup.not_allowed')]);
    expect(User::query()->where('email', $email)->exists())->toBe($allowed);
})->with([
    'listed email' => ['freelancer@studio.test', true],
    'other address at that domain' => ['someone@studio.test', false],
    'unlisted domain' => ['asha@other.test', false],
    'public domain listed by mistake' => ['asha@gmail.com', false],
]);

test('with no domains or emails, sign-up stays invites only and the login page has no link', function () {
    config(['socialhq.signup.domains' => [], 'socialhq.signup.emails' => []]);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('socialhq.signup.open', false));
    $this->get(route('register'))->assertNotFound();
    expect(User::query()->where('email', 'asha@acme.test')->exists())->toBeFalse();

    config(['socialhq.signup.domains' => ['acme.test']]);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('socialhq.signup.open', true));
});

test('an admin lets a sign-up into the workspaces they pick, reviewed like every member', function () {
    Bus::fake([SendNotification::class]);
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    verifyEmailOf($user);
    $request = AccessRequest::query()->where('user_id', $user->id)->firstOrFail();

    $this->actingAs($this->owner)
        ->get(route('socialhq.access-requests.index'))
        ->assertInertia(fn ($page) => $page->component('socialhq/AccessRequests')
            ->has('requests', 1)
            ->where('requests.0.email', 'asha@acme.test')
            ->has('workspaces', 2));

    $this->actingAs($this->owner)
        ->post(route('socialhq.access-requests.approve', $request->id), ['workspaces' => [$this->workspace->id], 'is_admin' => false])
        ->assertRedirect();

    $user->refresh();

    expect($user->workspaces()->pluck('workspaces.id')->all())->toBe([$this->workspace->id])
        ->and($user->isWorkspaceAdmin($this->workspace))->toBeFalse()
        ->and($user->requiresApprovalIn($this->workspace))->toBeTrue()
        ->and($user->current_workspace_id)->toBe($this->workspace->id)
        ->and($request->fresh()->status)->toBe(AccessRequest::APPROVED)
        ->and($request->fresh()->decided_by)->toBe($this->owner->id);

    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($user)
        && $job->mailable instanceof AccessApproved);

    $this->actingAs($user)->get(route('socialhq.access.pending'))->assertRedirect(route('app.home'));
});

test('an admin of one workspace can only give access to that workspace', function () {
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    verifyEmailOf($user);
    $request = AccessRequest::query()->where('user_id', $user->id)->firstOrFail();
    $admin = workspaceMember($this->otherWorkspace, 'admin');

    $this->actingAs($admin)
        ->post(route('socialhq.access-requests.approve', $request->id), ['workspaces' => [$this->workspace->id], 'is_admin' => false])
        ->assertSessionHasErrors('workspaces.0');

    expect($user->fresh()->workspaces()->count())->toBe(0);
});

test('members who are not admins cannot see or decide requests', function () {
    signUp('asha@acme.test');
    $request = AccessRequest::query()->firstOrFail();
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->get(route('socialhq.access-requests.index'))->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.access-requests.decline', $request->id))->assertForbidden();
});

test('a request whose email is not confirmed yet is not shown to admins', function () {
    signUp('asha@acme.test');

    $this->actingAs($this->owner)
        ->get(route('socialhq.access-requests.index'))
        ->assertInertia(fn ($page) => $page->has('requests', 0));
});

test('declining deletes the sign-up and keeps the request as history', function () {
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    verifyEmailOf($user);
    $request = AccessRequest::query()->where('user_id', $user->id)->firstOrFail();

    $this->actingAs($this->owner)
        ->post(route('socialhq.access-requests.decline', $request->id))
        ->assertRedirect();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and($request->fresh()->status)->toBe(AccessRequest::DECLINED)
        ->and($request->fresh()->user_id)->toBeNull();
});

test('the admins\' pending count is shared with the workspace switcher', function () {
    signUp('asha@acme.test');
    verifyEmailOf(User::query()->where('email', 'asha@acme.test')->firstOrFail());

    $this->actingAs($this->owner)->get(route('app.members'))
        ->assertInertia(fn ($page) => $page->where('socialhq.signup.pendingRequests', 1));
});

test('both emails render', function () {
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    $request = AccessRequest::query()->firstOrFail();
    $user->workspaces()->attach($this->workspace->id, membershipPivot('approval'));

    expect((new AccessRequested($request->id))->render())
        ->toContain('Asha Rai')
        ->toContain('asha@acme.test')
        ->toContain(route('socialhq.access-requests.index'))
        ->and((new AccessApproved($user->id))->render())
        ->toContain('Brand A');
});
