<?php

declare(strict_types=1);

use App\Models\User;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyStatus;
use App\SocialHq\Companies\PlatformAdmins;
use Illuminate\Support\Facades\Gate;

test('platform admin is granted and revoked only by the command', function () {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows(PlatformAdmins::GATE))->toBeFalse();

    $this->artisan('socialhq:platform-admin', ['email' => strtoupper($user->email)])->assertSuccessful();
    expect(Gate::forUser($user)->allows(PlatformAdmins::GATE))->toBeTrue();

    $this->artisan('socialhq:platform-admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect(PlatformAdmins::is($user))->toBeFalse();

    $this->artisan('socialhq:platform-admin', ['email' => 'nobody@example.test'])->assertFailed();
});

test('a company belongs to one account', function () {
    $company = Company::factory()->pending()->create();

    expect(Company::forAccount($company->account)?->id)->toBe($company->id)
        ->and($company->status)->toBe(CompanyStatus::Pending)
        ->and($company->isApproved())->toBeFalse();
});
