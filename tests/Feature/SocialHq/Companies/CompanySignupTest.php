<?php

declare(strict_types=1);

use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyEvent;
use App\SocialHq\Companies\CompanyStatus;
use App\SocialHq\Companies\Mail\CompanyRequested;
use App\SocialHq\Companies\PlatformAdmins;
use App\SocialHq\Signup\AccessRequest;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'trypost.self_hosted' => true,
        'socialhq.companies.requests' => true,
        'socialhq.companies.required' => true,
        'socialhq.signup.domains' => ['acme.test'],
        'socialhq.signup.emails' => [],
        'socialhq.signup.account_owner' => null,
    ]);

    // The team's company: the allow list joins it.
    $this->teamAccount = Account::factory()->create();
    $this->teamOwner = User::factory()->create(['account_id' => $this->teamAccount->id]);
    $this->teamAccount->update(['owner_id' => $this->teamOwner->id]);
    $this->teamWorkspace = Workspace::factory()->create(['account_id' => $this->teamAccount->id, 'user_id' => $this->teamOwner->id]);
    $this->teamWorkspace->members()->attach($this->teamOwner->id, membershipPivot('admin'));
    $this->teamOwner->update(['current_workspace_id' => $this->teamWorkspace->id]);
    Company::factory()->create(['account_id' => $this->teamAccount->id]);

    $this->platformAdmin = User::factory()->create(['account_id' => $this->teamAccount->id]);
    PlatformAdmins::grant($this->platformAdmin);
});

/**
 * @param  array<string, string>  $extra
 */
function registerForCompany(string $email, ?string $company = 'Himal Tea', array $extra = []): TestResponse
{
    return test()->post(route('register.store'), [
        'name' => 'Mina Gurung',
        'email' => $email,
        'password' => strongPassword(),
        'locale' => 'en',
        ...($company === null ? [] : ['company_name' => $company]),
        ...$extra,
    ]);
}

function confirmEmailOf(User $user): void
{
    test()->actingAs($user)->get(URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    ));
}

function googleSignsUp(string $email, string $name = 'Mina Gurung'): void
{
    $googleUser = (new SocialiteUser)
        ->setRaw(['sub' => 'google-mina', 'email' => $email, 'email_verified' => true])
        ->map(['id' => 'google-mina', 'name' => $name, 'email' => $email]);

    Socialite::shouldReceive('driver')->with('google-auth')->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($googleUser);
}

test('the register page offers the company field and the login page the sign-up link', function () {
    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('socialhq.signup.open', true));

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('socialhq.signup.companyRequests', true)
            ->where('socialhq.signup.joinsByEmail', true));
});

test('someone off the lists asks for a company: a pending company, no workspace, no login', function () {
    Bus::fake([SendNotification::class]);
    Notification::fake();

    registerForCompany('mina@himaltea.test')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('socialhq.company.requested'));

    $this->assertGuest();

    $user = User::query()->where('email', 'mina@himaltea.test')->firstOrFail();
    $company = Company::query()->where('account_id', $user->account_id)->firstOrFail();

    expect($company->status)->toBe(CompanyStatus::Pending)
        ->and($company->name)->toBe('Himal Tea')
        ->and($company->requested_by)->toBe($user->id)
        ->and($company->uses_platform_ai)->toBeFalse()
        ->and($user->account->owner_id)->toBe($user->id)
        ->and($user->account->name)->toBe('Himal Tea')
        ->and($user->workspaces()->count())->toBe(0)
        ->and($company->events()->pluck('action')->all())->toBe([CompanyEvent::REQUESTED]);

    Notification::assertSentTo($user, VerifyEmail::class);
    Bus::assertNotDispatched(SendNotification::class);

    $this->get(route('socialhq.company.requested'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/CompanyRequested')->where('email', 'mina@himaltea.test'));
});

test('asking for a company with an email that has an account says the same and sends a reset link', function () {
    Notification::fake();
    $existing = User::factory()->create(['email' => 'mina@himaltea.test']);

    registerForCompany('mina@himaltea.test')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('socialhq.company.requested'));

    $this->assertGuest();
    expect(User::query()->where('email', 'mina@himaltea.test')->count())->toBe(1)
        ->and(Company::query()->where('account_id', $existing->account_id)->exists())->toBeFalse();
    Notification::assertSentTo($existing, ResetPassword::class);
});

test('without a company name the form asks for one', function () {
    registerForCompany('mina@himaltea.test', null)
        ->assertSessionHasErrors(['company_name' => __('socialhq_companies.signup.name_required')]);

    expect(User::query()->where('email', 'mina@himaltea.test')->exists())->toBeFalse();
});

test('a work email on the list still joins the team, whatever company name it sends', function () {
    Bus::fake([SendNotification::class]);

    registerForCompany('asha@acme.test', 'Another Co')->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'asha@acme.test')->firstOrFail();

    expect($user->account_id)->toBe($this->teamAccount->id)
        ->and(AccessRequest::query()->where('user_id', $user->id)->value('status'))->toBe(AccessRequest::PENDING)
        ->and(Company::query()->count())->toBe(1);
    $this->assertAuthenticatedAs($user);
});

test('with company requests off, an email off the lists still can\'t sign up here', function () {
    config(['socialhq.companies.requests' => false]);

    registerForCompany('mina@himaltea.test')
        ->assertSessionHasErrors(['email' => __('socialhq.signup.not_allowed')]);

    expect(User::query()->where('email', 'mina@himaltea.test')->exists())->toBeFalse();
});

test('platform admins hear about a company request once its email is confirmed, and only once', function () {
    Bus::fake([SendNotification::class]);
    registerForCompany('mina@himaltea.test');
    $user = User::query()->where('email', 'mina@himaltea.test')->firstOrFail();

    Bus::assertNotDispatched(SendNotification::class);

    confirmEmailOf($user);
    confirmEmailOf($user->fresh());

    Bus::assertDispatchedTimes(SendNotification::class, 1);
    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($this->platformAdmin)
        && $job->mailable instanceof CompanyRequested);
});

test('the owner of a pending company waits on one page and creates no workspace', function () {
    registerForCompany('mina@himaltea.test');
    $user = User::query()->where('email', 'mina@himaltea.test')->firstOrFail();

    $this->actingAs($user)->get(route('app.calendar'))->assertRedirect(route('socialhq.company.waiting'));
    $this->get(route('app.workspaces.create'))->assertRedirect(route('socialhq.company.waiting'));
    $this->post(route('app.workspaces.store'), ['name' => 'Tea'])->assertRedirect(route('socialhq.company.waiting'));

    $this->get(route('socialhq.company.waiting'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/CompanyPending')
            ->where('company.status', 'pending')
            ->where('company.name', 'Himal Tea')
            ->where('verified', false));

    expect($user->can('create', Workspace::class))->toBeFalse()
        ->and(Workspace::query()->where('account_id', $user->account_id)->exists())->toBeFalse();
});

test('the owner renames a waiting company, not a decided one', function () {
    $company = Company::factory()->pending()->create(['name' => 'Mina Gurung']);
    $owner = User::factory()->create(['account_id' => $company->account_id]);
    $company->account->update(['owner_id' => $owner->id]);

    $this->actingAs($owner)->put(route('socialhq.company.update'), ['name' => '  Himal   Tea '])
        ->assertRedirect(route('socialhq.company.waiting'));

    expect($company->fresh()->name)->toBe('Himal Tea')
        ->and($company->events()->where('action', CompanyEvent::RENAMED)->exists())->toBeTrue();

    $this->put(route('socialhq.company.update'), ['name' => ''])->assertSessionHasErrors('name');

    $company->update(['status' => CompanyStatus::Approved]);
    $this->put(route('socialhq.company.update'), ['name' => 'Other'])->assertNotFound();
    expect($company->fresh()->name)->toBe('Himal Tea');
});

test('a declined company sees the note and may ask again once a day has passed', function () {
    Bus::fake([SendNotification::class]);
    $company = Company::factory()->declined('Tell us more about your brands.')->create();
    $owner = User::factory()->create(['account_id' => $company->account_id]);
    $company->account->update(['owner_id' => $owner->id]);

    $this->actingAs($owner)->get(route('socialhq.company.waiting'))
        ->assertInertia(fn ($page) => $page
            ->where('company.status', 'declined')
            ->where('company.note', 'Tell us more about your brands.')
            ->whereNot('company.can_ask_again_at', null));

    $this->post(route('socialhq.company.ask-again'))->assertSessionHas('flash.error');
    expect($company->fresh()->status)->toBe(CompanyStatus::Declined);

    $this->travel(25)->hours();

    $this->post(route('socialhq.company.ask-again'))->assertRedirect(route('socialhq.company.waiting'));

    $company->refresh();
    expect($company->status)->toBe(CompanyStatus::Pending)
        ->and($company->decision_note)->toBeNull()
        ->and($company->events()->where('action', CompanyEvent::ASKED_AGAIN)->exists())->toBeTrue();
    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->mailable instanceof CompanyRequested);
});

test('a google sign-up off the lists asks for a company named after the person, and platform admins hear at once', function () {
    Bus::fake([SendNotification::class]);
    googleSignsUp('mina@himaltea.test');

    $this->get(route('auth.google.callback'))->assertRedirect();

    $user = User::query()->where('email', 'mina@himaltea.test')->firstOrFail();
    $company = Company::query()->where('account_id', $user->account_id)->firstOrFail();

    expect($company->status)->toBe(CompanyStatus::Pending)
        ->and($company->name)->toBe('Mina Gurung')
        ->and($user->workspaces()->count())->toBe(0);
    $this->assertAuthenticatedAs($user);
    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->mailable instanceof CompanyRequested);

    $this->get(route('app.welcome'))->assertRedirect(route('socialhq.company.waiting'));
});

test('with company requests off, a google sign-up off the lists is refused', function () {
    config(['socialhq.companies.requests' => false]);
    googleSignsUp('mina@himaltea.test');

    $this->get(route('auth.google.callback'))->assertRedirect(route('login'));

    expect(User::query()->where('email', 'mina@himaltea.test')->exists())->toBeFalse();
});

test('an invited person registers with an empty account, can\'t create workspaces and still accepts the invite', function () {
    $invite = Invite::factory()->create([
        'account_id' => $this->teamAccount->id,
        'email' => 'ravi@elsewhere.test',
        'invited_by' => $this->teamOwner->id,
        'workspaces' => [$this->teamWorkspace->id],
    ]);

    // The company field hides itself when the page has an invite.
    $this->get(route('register', ['invite' => $invite->id]))
        ->assertInertia(fn ($page) => $page->where('invite', $invite->id));

    registerForCompany('ravi@elsewhere.test', null, ['invite' => $invite->id])->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'ravi@elsewhere.test')->firstOrFail();

    expect(Company::query()->where('account_id', $user->account_id)->exists())->toBeFalse()
        ->and($user->can('create', Workspace::class))->toBeFalse();

    $this->get(route('app.workspaces.create'))->assertRedirect(route('socialhq.company.start'));
    $this->get(route('socialhq.company.start'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/CompanyStart')->where('canAskForCompany', true));
    $this->post(route('app.workspaces.store'), ['name' => 'Mine'])->assertForbidden();

    $this->post(route('app.invites.accept', $invite))->assertRedirect();

    expect($user->fresh()->account_id)->toBe($this->teamAccount->id)
        ->and($user->fresh()->belongsToWorkspace($this->teamWorkspace))->toBeTrue();
});

test('someone with an empty account asks to start a company from the no-workspace page', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $this->actingAs($user)->post(route('socialhq.company.store'), ['name' => 'Ravi Studio'])
        ->assertRedirect(route('socialhq.company.waiting'));

    expect(Company::query()->where('account_id', $account->id)->value('status'))->toBe(CompanyStatus::Pending);

    // Only once: the account is a company now.
    $this->post(route('socialhq.company.store'), ['name' => 'Again'])->assertForbidden();
    expect(Company::query()->where('account_id', $account->id)->count())->toBe(1);
});

test('a member removed from every workspace sees the no-workspace page without the company form', function () {
    $member = User::factory()->create(['account_id' => $this->teamAccount->id]);

    $this->actingAs($member)->get(route('app.workspaces.create'))->assertRedirect(route('socialhq.company.start'));
    $this->get(route('socialhq.company.start'))
        ->assertInertia(fn ($page) => $page->where('canAskForCompany', false));
    $this->post(route('socialhq.company.store'), ['name' => 'Mine'])->assertForbidden();
});

test('the owner of an approved company creates workspaces', function () {
    $this->actingAs($this->teamOwner)->get(route('app.workspaces.create'))->assertOk();
    $this->post(route('app.workspaces.store'), ['name' => 'Brand B'])->assertRedirect();

    expect(Workspace::query()->where('account_id', $this->teamAccount->id)->count())->toBe(2);
});

