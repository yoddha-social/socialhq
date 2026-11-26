<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyEvent;
use App\SocialHq\Companies\CompanyStatus;
use Database\Factories\AccountFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * php artisan socialhq:company: the way to make a company while company
 * requests are off, as they are where strangers shouldn't sign up (audit
 * 2026-11-24, SEC-06 and X11).
 */
beforeEach(function () {
    config(['socialhq.companies.requests' => false]);
    Mail::fake();
    Bus::fake();
});

test('with sign-up off, the register page is closed and the command makes an approved company and its owner', function () {
    $this->get(route('register'))->assertNotFound();

    expect(Artisan::call('socialhq:company', ['email' => ' Mina@HimalTea.test ', 'name' => '  Himal   Tea ', '--owner-name' => 'Mina Gurung']))->toBe(0);
    $output = Artisan::output();

    $owner = User::query()->where('email', 'mina@himaltea.test')->firstOrFail();
    $company = Company::forAccount($owner->account);

    expect($owner->name)->toBe('Mina Gurung')
        ->and($owner->isAccountOwner())->toBeTrue()
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->password)->toBeNull()
        ->and($owner->account->name)->toBe('Himal Tea')
        ->and($owner->account->workspaces()->count())->toBe(0)
        ->and($company?->name)->toBe('Himal Tea')
        ->and($company?->status)->toBe(CompanyStatus::Approved)
        ->and($company?->uses_platform_ai)->toBeFalse()
        ->and($company?->decided_by)->toBeNull()
        ->and($company?->events()->where('action', CompanyEvent::APPROVED)->whereNull('user_id')->exists())->toBeTrue();

    // The password link in the output works, and nothing was emailed or queued.
    preg_match('#/reset-password/([^?\s]+)#', $output, $token);
    expect($token)->not->toBeEmpty()
        ->and(Password::broker()->tokenExists($owner, $token[1]))->toBeTrue()
        ->and($output)->toContain('php artisan socialhq:platform-admin mina@himaltea.test');
    Mail::assertNothingOutgoing();
    Bus::assertNothingDispatched();

    $this->actingAs($owner)->get(route('app.workspaces.create'))->assertOk();
    $this->post(route('app.workspaces.store'), ['name' => 'Himal Tea'])->assertRedirect();
    expect(Workspace::query()->where('account_id', $owner->account_id)->count())->toBe(1);
});

test('the owner\'s name defaults to the email before the @, and --platform-ai lets the company use our AI keys', function () {
    $this->artisan('socialhq:company', ['email' => 'mina@himaltea.test', 'name' => 'Himal Tea', '--platform-ai' => true])->assertSuccessful();

    $owner = User::query()->where('email', 'mina@himaltea.test')->firstOrFail();
    $company = Company::forAccount($owner->account);

    expect($owner->name)->toBe('mina')
        ->and($company?->uses_platform_ai)->toBeTrue()
        ->and($company?->events()->where('action', CompanyEvent::PLATFORM_AI_ON)->exists())->toBeTrue();
});

test('someone who already has an empty account of their own becomes the company\'s owner', function () {
    $owner = AccountFactory::withoutCompany(fn (): User => User::factory()->create(['email' => 'mina@himaltea.test']));

    $this->artisan('socialhq:company', ['email' => 'mina@himaltea.test', 'name' => 'Himal Tea'])
        ->doesntExpectOutputToContain('/reset-password/')
        ->assertSuccessful();

    expect(User::query()->where('email', 'mina@himaltea.test')->count())->toBe(1)
        ->and(Company::forAccount($owner->account)?->isApproved())->toBeTrue();
});

test('an account whose email was never confirmed doesn\'t get a company: a stranger may have registered the address', function () {
    $squatter = AccountFactory::withoutCompany(fn (): User => User::factory()->unverified()->create(['email' => 'mina@himaltea.test']));

    $this->artisan('socialhq:company', ['email' => 'mina@himaltea.test', 'name' => 'Himal Tea'])->assertFailed();

    expect(Company::forAccount($squatter->account))->toBeNull();
});

test('someone who works in another company\'s account can\'t own a new company', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id, 'email' => 'staff@himaltea.test']);
    $companies = Company::query()->count();

    $this->artisan('socialhq:company', ['email' => 'staff@himaltea.test', 'name' => 'Staff Co'])->assertFailed();

    expect(Company::query()->count())->toBe($companies)
        ->and($member->account->fresh()->name)->not->toBe('Staff Co');
});

test('an approved company is left as it is, and a waiting or paused one is decided on the platform page', function (CompanyStatus $status, bool $succeeds) {
    $owner = AccountFactory::withoutCompany(fn (): User => User::factory()->create(['email' => 'mina@himaltea.test']));
    $company = Company::factory()->create(['account_id' => $owner->account_id, 'name' => 'Himal Tea']);
    $company->forceFill(['status' => $status])->save();

    $run = $this->artisan('socialhq:company', ['email' => 'mina@himaltea.test', 'name' => 'Other Name']);
    $succeeds ? $run->assertSuccessful() : $run->assertFailed();

    expect($company->fresh()->status)->toBe($status)
        ->and($company->fresh()->name)->toBe('Himal Tea');
})->with([
    'approved' => [CompanyStatus::Approved, true],
    'pending' => [CompanyStatus::Pending, false],
    'declined' => [CompanyStatus::Declined, false],
    'suspended' => [CompanyStatus::Suspended, false],
]);

test('a bad email or an empty or overlong name creates nothing', function (string $email, string $name) {
    $users = User::query()->count();
    $companies = Company::query()->count();

    $this->artisan('socialhq:company', ['email' => $email, 'name' => $name])->assertFailed();

    expect(User::query()->count())->toBe($users)
        ->and(Company::query()->count())->toBe($companies);
})->with([
    'not an email' => ['mina-at-himaltea', 'Himal Tea'],
    'empty name' => ['mina@himaltea.test', '   '],
    'name over 120 characters' => ['mina@himaltea.test', str_repeat('a', 121)],
]);
