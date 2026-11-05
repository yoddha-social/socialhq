<?php

declare(strict_types=1);

use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Signup\AccessRequest;
use App\SocialHq\Signup\AccessRequests;
use App\SocialHq\Signup\Mail\AccessApproved;
use App\SocialHq\Signup\Mail\AccessRequested;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

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

test('both emails render with the Social HQ header and their mail.php copy', function () {
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    $request = AccessRequest::query()->firstOrFail();
    $user->workspaces()->attach($this->workspace->id, membershipPivot('approval'));

    $requested = (new AccessRequested($request->id))->render();
    $approved = (new AccessApproved($user->id))->render();

    expect($requested)
        ->toContain('Asha Rai')
        ->toContain('asha@acme.test')
        ->toContain(route('socialhq.access-requests.index'))
        ->toContain(e(__('mail.socialhq_access_requested.heading')))
        ->toContain(e(__('mail.socialhq_access_requested.button')))
        ->and($approved)
        ->toContain('Brand A')
        ->toContain(e(__('mail.socialhq_access_approved.button', ['app' => config('app.name')])));

    foreach ([$requested, $approved] as $html) {
        expect($html)
            ->toContain('href="'.config('app.url').'"')
            ->not->toContain('trypost.it')
            ->not->toContain('mail.socialhq_access');
    }

    expect((new AccessRequested($request->id))->envelope()->subject)->toBe(__('mail.socialhq_access_requested.subject', ['name' => 'Asha Rai', 'app' => config('app.name')]));
});

/**
 * Google answers the callback with this address.
 */
function googleSignsIn(string $email, bool $verified = true, string $googleId = 'google-asha'): void
{
    $googleUser = (new SocialiteUser)
        ->setRaw(['sub' => $googleId, 'email' => $email, 'email_verified' => $verified])
        ->map(['id' => $googleId, 'name' => 'Asha Rai', 'email' => $email]);

    Socialite::shouldReceive('driver')->with('google-auth')->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($googleUser);
}

test('a google sign-in to an unconfirmed sign-up takes it over from whoever set its password', function () {
    Bus::fake([SendNotification::class]);
    signUp('asha@acme.test');
    $this->post(route('logout'));
    $squatter = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    $squatter->forceFill(['remember_token' => 'squatter-remember-token'])->save();
    DB::table('sessions')->insert([
        'id' => 'squatter-session',
        'user_id' => $squatter->id,
        'ip_address' => '203.0.113.9',
        'user_agent' => 'Squatter',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
    config(['session.driver' => 'database']);

    googleSignsIn('asha@acme.test');
    $this->get(route('auth.google.callback'))->assertRedirect();

    $user = $squatter->fresh();

    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check(strongPassword(), $user->password))->toBeFalse()
        ->and($user->remember_token)->not->toBe('squatter-remember-token')
        ->and(DB::table('sessions')->where('id', 'squatter-session')->exists())->toBeFalse();
    $this->assertAuthenticatedAs($user);

    // The Verified event reached our listener: the admins hear about the request.
    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($this->owner)
        && $job->mailable instanceof AccessRequested);
});

test('an address google has not verified signs in to nothing and creates nothing', function () {
    signUp('asha@acme.test');
    $this->post(route('logout'));

    googleSignsIn('asha@acme.test', verified: false);
    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('socialhq.signup.oauth_email_unverified')]);

    $this->assertGuest();
    expect(User::query()->where('email', 'asha@acme.test')->firstOrFail()->hasVerifiedEmail())->toBeFalse();

    googleSignsIn('ravi@acme.test', verified: false, googleId: 'google-ravi');
    $this->get(route('auth.google.callback'))->assertRedirect(route('login'));

    expect(User::query()->where('email', 'ravi@acme.test')->exists())->toBeFalse();
});

test('an email that may not sign up never learns whether it has an account', function () {
    User::factory()->create(['email' => 'boss@other.test']);

    signUp('boss@other.test')->assertSessionHasErrors('email');

    expect(session('errors')->get('email'))->toBe([__('socialhq.signup.not_allowed')]);
});

test('signing up by email is limited per address', function () {
    foreach (range(1, 5) as $attempt) {
        signUp("someone{$attempt}@other.test")->assertSessionHasErrors('email');
    }

    signUp('someone6@other.test')->assertTooManyRequests();
});

test('a declined person who signs up again does not email the admins again within the hour', function () {
    Bus::fake([SendNotification::class]);

    $signUpAndConfirm = function (): User {
        signUp('asha@acme.test')->assertSessionHasNoErrors();
        $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
        verifyEmailOf($user);
        $this->post(route('logout'));

        return $user;
    };
    $decline = function (User $user): void {
        $request = AccessRequest::query()->where('user_id', $user->id)->firstOrFail();
        $this->actingAs($this->owner)->post(route('socialhq.access-requests.decline', $request->id))->assertRedirect();
        $this->post(route('logout'));
    };

    $decline($signUpAndConfirm());
    $decline($signUpAndConfirm());
    Bus::assertDispatchedTimes(SendNotification::class, 1);

    $this->travel(61)->minutes();
    $signUpAndConfirm();
    Bus::assertDispatchedTimes(SendNotification::class, 2);
});

test('approving a request someone already declined changes nothing, and the reverse deletes no one', function () {
    signUp('asha@acme.test');
    $asha = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    verifyEmailOf($asha);
    $this->post(route('logout'));
    signUp('ravi@acme.test');
    $ravi = User::query()->where('email', 'ravi@acme.test')->firstOrFail();
    verifyEmailOf($ravi);

    // Both admins loaded the request before either decided.
    $ashaRequest = AccessRequest::query()->where('user_id', $asha->id)->firstOrFail();
    $raviRequest = AccessRequest::query()->where('user_id', $ravi->id)->firstOrFail();

    expect(AccessRequests::decline($ashaRequest->fresh(), $this->owner))->toBeTrue()
        ->and(AccessRequests::approve($ashaRequest, $this->owner, [$this->workspace->id], false))->toBeFalse()
        ->and($ashaRequest->fresh()->status)->toBe(AccessRequest::DECLINED);

    expect(AccessRequests::approve($raviRequest->fresh(), $this->owner, [$this->workspace->id], false))->toBeTrue()
        ->and(AccessRequests::decline($raviRequest, $this->owner))->toBeFalse()
        ->and(User::query()->whereKey($ravi->id)->exists())->toBeTrue()
        ->and($raviRequest->fresh()->status)->toBe(AccessRequest::APPROVED);

    // The admin who was second is told, without an error page.
    $this->actingAs($this->owner)
        ->from(route('socialhq.access-requests.index'))
        ->post(route('socialhq.access-requests.approve', $ashaRequest->id), ['workspaces' => [$this->workspace->id], 'is_admin' => false])
        ->assertRedirect(route('socialhq.access-requests.index'))
        ->assertSessionHas('flash.error', __('socialhq.signup.requests.already_decided'));
    $this->actingAs($this->owner)
        ->post(route('socialhq.access-requests.decline', $raviRequest->id))
        ->assertSessionHas('flash.error', __('socialhq.signup.requests.already_decided'));
});

test('with no owner configured, sign-up joins the oldest account that owns a workspace', function () {
    // Someone invited elsewhere holds an empty account of their own until they accept.
    Account::factory()->create();

    signUp('asha@acme.test')->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'asha@acme.test')->value('account_id'))->toBe($this->account->id);
});

test('the configured owner is found whatever the case of the address', function () {
    $other = Account::factory()->create();
    // Google and GitHub sign-ups keep the capitals the provider sent.
    $otherOwner = User::factory()->create(['account_id' => $other->id, 'email' => 'Lead_Ops@Acme.test']);
    $other->update(['owner_id' => $otherOwner->id]);
    User::factory()->create(['email' => 'leadxops@acme.test']);
    config(['socialhq.signup.account_owner' => 'lead_ops@ACME.test']);

    signUp('asha@acme.test')->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'asha@acme.test')->value('account_id'))->toBe($other->id);
});

test('when no account can take sign-ups, sign-up stays closed and the log says why once', function () {
    config(['socialhq.signup.account_owner' => 'nobody@acme.test']);
    Log::spy();

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('socialhq.signup.open', false));
    $this->get(route('login'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'SOCIALHQ_SIGNUP_ACCOUNT_OWNER'))
        ->once();
});

test('an invite id that no longer exists does not let a sign-up skip approval', function () {
    $this->withSession(['pending_invite_id' => (string) Str::uuid()]);

    signUp('asha@acme.test')->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();

    expect($user->account_id)->toBe($this->account->id)
        ->and(AccessRequest::query()->where('user_id', $user->id)->value('status'))->toBe(AccessRequest::PENDING)
        ->and(Account::query()->count())->toBe(1);
});

test('someone waiting for access can accept an invite, which closes their request', function () {
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    verifyEmailOf($user);
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'asha@acme.test',
        'workspaces' => [$this->otherWorkspace->id],
    ]);

    $this->actingAs($user)->get(route('app.invites.show', $invite))->assertOk();
    $this->actingAs($user)->post(route('app.invites.accept', $invite))->assertRedirect(route('app.calendar'));

    $request = AccessRequest::query()->where('email', 'asha@acme.test')->firstOrFail();

    expect($user->fresh()->current_workspace_id)->toBe($this->otherWorkspace->id)
        ->and($request->status)->toBe(AccessRequest::APPROVED)
        ->and($request->note)->toBe(AccessRequests::JOINED_BY_INVITE)
        ->and($request->decided_by)->toBeNull();
    $this->actingAs($user->fresh())->get(route('socialhq.access.pending'))->assertRedirect(route('app.home'));
});

test('someone waiting for access who declines an invite keeps waiting', function () {
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'asha@acme.test',
        'workspaces' => [$this->otherWorkspace->id],
    ]);

    $this->actingAs($user)->post(route('app.invites.decline', $invite))->assertRedirect();

    expect(Invite::query()->whereKey($invite->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(AccessRequest::query()->where('user_id', $user->id)->value('status'))->toBe(AccessRequest::PENDING);
});

test('declining deletes the files of the person\'s avatar', function () {
    Storage::fake();
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    verifyEmailOf($user);
    $avatar = Media::factory()->avatar()->create(['mediable_type' => $user->getMorphClass(), 'mediable_id' => $user->id]);
    Storage::put($avatar->path, 'avatar');
    $request = AccessRequest::query()->where('user_id', $user->id)->firstOrFail();

    $this->actingAs($this->owner)->post(route('socialhq.access-requests.decline', $request->id))->assertRedirect();

    Storage::assertMissing($avatar->path);
});

test('the pending count for the shared props is one query, and 0 for members who cannot decide', function () {
    signUp('asha@acme.test');
    verifyEmailOf(User::query()->where('email', 'asha@acme.test')->firstOrFail());
    $member = workspaceMember($this->workspace);
    $admin = workspaceMember($this->otherWorkspace, 'admin');
    $owner = $this->owner->fresh();

    DB::enableQueryLog();
    $counts = [
        AccessRequests::pendingCountFor($owner),
        AccessRequests::pendingCountFor($admin),
        AccessRequests::pendingCountFor($member),
    ];

    expect($counts)->toBe([1, 1, 0])
        ->and(DB::getQueryLog())->toHaveCount(3);
});

test('approving without a workspace says to pick one', function () {
    signUp('asha@acme.test');
    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();
    verifyEmailOf($user);
    $request = AccessRequest::query()->where('user_id', $user->id)->firstOrFail();

    $this->actingAs($this->owner)
        ->post(route('socialhq.access-requests.approve', $request->id), ['workspaces' => [], 'is_admin' => false])
        ->assertSessionHasErrors(['workspaces' => __('socialhq.signup.requests.pick_workspace')]);
});

test('members who cannot decide get no waiting count, so no banner or menu item', function () {
    signUp('asha@acme.test');
    verifyEmailOf(User::query()->where('email', 'asha@acme.test')->firstOrFail());
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page->where('socialhq.signup.pendingRequests', 0));
});
