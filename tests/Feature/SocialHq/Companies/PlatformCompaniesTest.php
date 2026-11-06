<?php

declare(strict_types=1);

use App\Jobs\SendNotification;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyDecisions;
use App\SocialHq\Companies\CompanyEvent;
use App\SocialHq\Companies\CompanyStatus;
use App\SocialHq\Companies\Mail\CompanyApproved;
use App\SocialHq\Companies\Mail\CompanyDeclined;
use App\SocialHq\Companies\Mail\CompanyRequested;
use App\SocialHq\Companies\PlatformAdmins;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config([
        'trypost.self_hosted' => true,
        'socialhq.companies.requests' => true,
        'socialhq.companies.required' => true,
        'socialhq.companies.contact_email' => null,
    ]);

    // The platform admin works in an approved company of their own.
    $adminAccount = Account::factory()->create();
    $this->admin = User::factory()->create(['account_id' => $adminAccount->id]);
    $adminAccount->update(['owner_id' => $this->admin->id]);
    $adminWorkspace = Workspace::factory()->create(['account_id' => $adminAccount->id, 'user_id' => $this->admin->id]);
    $adminWorkspace->members()->attach($this->admin->id, membershipPivot('admin'));
    $this->admin->update(['current_workspace_id' => $adminWorkspace->id]);
    $this->adminCompany = Company::factory()->create(['account_id' => $adminAccount->id, 'name' => 'Zeta Platform']);
    PlatformAdmins::grant($this->admin);

    // A request waiting for a decision, its owner's email confirmed.
    $this->company = Company::factory()->pending()->create(['name' => 'Himal Tea']);
    $this->owner = User::factory()->create(['account_id' => $this->company->account_id, 'email' => 'mina@himaltea.test']);
    $this->company->account->update(['owner_id' => $this->owner->id]);
    $this->company->update(['requested_by' => $this->owner->id, 'requested_at' => now()]);
});

/**
 * An approved company with a workspace, its owner and one member.
 *
 * @return array{0: Company, 1: User, 2: User}
 */
function approvedCompanyWithMember(): array
{
    $company = Company::factory()->create(['name' => 'Everest Bank']);
    $owner = User::factory()->create(['account_id' => $company->account_id]);
    $company->account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create(['account_id' => $company->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    $member = User::factory()->create(['account_id' => $company->account_id, 'current_workspace_id' => $workspace->id]);
    $workspace->members()->attach($member->id, membershipPivot('member'));

    return [$company, $owner, $member];
}

test('only platform admins find the companies page and its actions', function () {
    $this->actingAs($this->owner)->get(route('socialhq.platform.companies.index'))->assertRedirect(route('socialhq.company.waiting'));

    [, $otherOwner] = approvedCompanyWithMember();
    $this->actingAs($otherOwner)->get(route('socialhq.platform.companies.index'))->assertNotFound();
    $this->post(route('socialhq.platform.companies.approve', $this->company->id))->assertNotFound();
    $this->post(route('socialhq.platform.companies.decline', $this->company->id), ['note' => 'No'])->assertNotFound();
    $this->put(route('socialhq.platform.companies.platform-ai', $this->company->id), ['enabled' => true])->assertNotFound();

    expect($this->company->fresh()->status)->toBe(CompanyStatus::Pending);
});

test('a platform admin sees requests and companies on one page', function () {
    $unverifiedCompany = Company::factory()->pending()->create(['name' => 'Unconfirmed Co', 'requested_at' => now()->addMinute()]);
    $unverifiedOwner = User::factory()->unverified()->create(['account_id' => $unverifiedCompany->account_id]);
    $unverifiedCompany->account->update(['owner_id' => $unverifiedOwner->id]);
    [$approved] = approvedCompanyWithMember();

    $this->actingAs($this->admin)->get(route('socialhq.platform.companies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/platform/Companies')
            ->has('requests', 2)
            ->where('requests.0.name', 'Himal Tea')
            ->where('requests.0.owner_email', 'mina@himaltea.test')
            ->where('requests.0.verified', true)
            ->where('requests.1.verified', false)
            ->has('companies', 2)
            ->where('companies.0.name', $approved->name)
            ->where('companies.0.workspaces_count', 1)
            ->where('companies.0.members_count', 2)
            ->where('companies.0.status', 'approved')
            ->where('socialhq.platform.admin', true)
            ->where('socialhq.platform.pendingCompanies', 1));
});

test('approving lets the owner create workspaces and emails them', function () {
    Bus::fake([SendNotification::class]);

    $this->actingAs($this->admin)->post(route('socialhq.platform.companies.approve', $this->company->id))
        ->assertSessionHas('flash.bannerStyle', 'success');

    $this->company->refresh();
    expect($this->company->status)->toBe(CompanyStatus::Approved)
        ->and($this->company->decided_by)->toBe($this->admin->id)
        ->and($this->company->events()->where('action', CompanyEvent::APPROVED)->value('user_id'))->toBe($this->admin->id);
    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($this->owner)
        && $job->mailable instanceof CompanyApproved);

    $this->actingAs($this->owner)->get(route('app.workspaces.create'))->assertOk();
    $this->post(route('app.workspaces.store'), ['name' => 'Himal Tea'])->assertRedirect();
    expect(Workspace::query()->where('account_id', $this->company->account_id)->count())->toBe(1);
});

test('a company whose owner hasn\'t confirmed their email can\'t be approved yet', function () {
    $this->owner->update(['email_verified_at' => null]);

    $this->actingAs($this->admin)->post(route('socialhq.platform.companies.approve', $this->company->id))
        ->assertSessionHas('flash.error', __('socialhq_companies.platform.flash.not_verified'));

    expect($this->company->fresh()->status)->toBe(CompanyStatus::Pending);
});

test('declining needs a note, which the owner reads with their email', function () {
    Bus::fake([SendNotification::class]);

    $this->actingAs($this->admin)->post(route('socialhq.platform.companies.decline', $this->company->id), ['note' => ''])
        ->assertSessionHasErrors(['note' => __('socialhq_companies.platform.note_required')]);
    expect($this->company->fresh()->status)->toBe(CompanyStatus::Pending);

    $this->post(route('socialhq.platform.companies.decline', $this->company->id), ['note' => 'Tell us about your brands.'])
        ->assertSessionHas('flash.bannerStyle', 'success');

    $this->company->refresh();
    expect($this->company->status)->toBe(CompanyStatus::Declined)
        ->and($this->company->decision_note)->toBe('Tell us about your brands.');
    Bus::assertDispatched(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($this->owner)
        && $job->mailable instanceof CompanyDeclined);

    $this->actingAs($this->owner)->get(route('app.calendar'))->assertRedirect(route('socialhq.company.waiting'));
});

test('when two platform admins decide at once, the first wins and the second is told', function () {
    Bus::fake([SendNotification::class]);
    $secondAdmin = User::factory()->create();
    PlatformAdmins::grant($secondAdmin);

    $this->actingAs($this->admin)->post(route('socialhq.platform.companies.approve', $this->company->id));
    $this->actingAs($secondAdmin)->post(route('socialhq.platform.companies.decline', $this->company->id), ['note' => 'No'])
        ->assertSessionHas('flash.error', __('socialhq_companies.platform.flash.already_decided'));

    $this->company->refresh();
    expect($this->company->status)->toBe(CompanyStatus::Approved)
        ->and($this->company->decided_by)->toBe($this->admin->id)
        ->and($this->company->events()->whereIn('action', [CompanyEvent::APPROVED, CompanyEvent::DECLINED])->count())->toBe(1)
        ->and(CompanyDecisions::approve($this->company, $secondAdmin))->toBeFalse();
    Bus::assertDispatchedTimes(SendNotification::class, 1);
});

test('suspending pauses everyone in the company until a platform admin restores it', function () {
    [$company, $owner, $member] = approvedCompanyWithMember();

    $this->actingAs($this->admin)->post(route('socialhq.platform.companies.suspend', $company->id), ['note' => ''])
        ->assertSessionHasErrors('note');
    $this->post(route('socialhq.platform.companies.suspend', $company->id), ['note' => 'Unpaid invoice.'])
        ->assertSessionHas('flash.bannerStyle', 'success');
    expect($company->fresh()->status)->toBe(CompanyStatus::Suspended);

    $this->actingAs($member)->get(route('app.calendar'))->assertRedirect(route('socialhq.company.paused'));
    $this->get(route('app.workspaces.index'))->assertRedirect(route('socialhq.company.paused'));
    $this->get(route('socialhq.company.paused'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/CompanyPaused')
            ->where('isOwner', false)
            ->where('note', null)
            ->where('contact', $owner->email));

    $this->actingAs($owner)->get(route('socialhq.company.paused'))
        ->assertInertia(fn ($page) => $page->where('isOwner', true)
            ->where('note', 'Unpaid invoice.')
            ->where('contact', $this->admin->email));

    $this->actingAs($this->admin)->post(route('socialhq.platform.companies.restore', $company->id))
        ->assertSessionHas('flash.bannerStyle', 'success');

    $this->actingAs($member)->get(route('app.workspaces.index'))->assertOk();
    $this->get(route('socialhq.company.paused'))->assertRedirect(route('app.home'));
    expect($company->events()->pluck('action')->all())->toBe([CompanyEvent::SUSPENDED, CompanyEvent::RESTORED]);
});

test('a paused company\'s API keys stop working until it is restored', function () {
    $api = createApiTestToken();
    $company = Company::factory()->create(['account_id' => $api['workspace']->account_id]);
    $callApi = fn () => $this->withHeaders(['Authorization' => "Bearer {$api['plain_token']}"])
        ->getJson(route('api.workspace.show'), ['HTTP_HOST' => 'api.trypost.test']);

    $callApi()->assertOk();

    CompanyDecisions::suspend($company, $this->admin, 'Unpaid invoice.');
    $callApi()->assertForbidden()->assertJson(['message' => __('socialhq_companies.paused.api')]);

    CompanyDecisions::restore($company->fresh(), $this->admin);
    $callApi()->assertOk();
});

test('a configured contact address is who a paused company\'s owner writes to', function () {
    config(['socialhq.companies.contact_email' => 'help@socialhq.test']);
    [$company, $owner] = approvedCompanyWithMember();
    $company->update(['status' => CompanyStatus::Suspended, 'decided_by' => $this->admin->id]);

    $this->actingAs($owner)->get(route('socialhq.company.paused'))
        ->assertInertia(fn ($page) => $page->where('contact', 'help@socialhq.test'));
});

test('restoring or pausing only applies to a company in the right state', function () {
    $this->actingAs($this->admin)->post(route('socialhq.platform.companies.restore', $this->company->id))
        ->assertSessionHas('flash.error');
    $this->post(route('socialhq.platform.companies.suspend', $this->company->id), ['note' => 'No'])
        ->assertSessionHas('flash.error');

    expect($this->company->fresh()->status)->toBe(CompanyStatus::Pending);
});

test('platform admins are paused with their own company but still reach the platform pages', function () {
    $this->adminCompany->update(['status' => CompanyStatus::Suspended]);

    $this->actingAs($this->admin)->get(route('app.calendar'))->assertRedirect(route('socialhq.company.paused'));
    $this->get(route('socialhq.platform.companies.index'))->assertOk();
    $this->post(route('socialhq.platform.companies.restore', $this->adminCompany->id))->assertSessionHas('flash.bannerStyle', 'success');
    $this->get(route('app.workspaces.index'))->assertOk();
});

test('a platform admin decides which companies may use the platform AI keys', function () {
    [$company] = approvedCompanyWithMember();

    $this->actingAs($this->admin)->put(route('socialhq.platform.companies.platform-ai', $company->id), ['enabled' => true])->assertRedirect();
    $this->put(route('socialhq.platform.companies.platform-ai', $company->id), ['enabled' => true])->assertRedirect();
    expect($company->fresh()->uses_platform_ai)->toBeTrue();

    $this->put(route('socialhq.platform.companies.platform-ai', $company->id), ['enabled' => false]);
    expect($company->fresh()->uses_platform_ai)->toBeFalse()
        ->and($company->events()->pluck('action')->all())->toBe([CompanyEvent::PLATFORM_AI_ON, CompanyEvent::PLATFORM_AI_OFF]);

    $this->put(route('socialhq.platform.companies.platform-ai', $company->id), ['enabled' => 'maybe'])->assertSessionHasErrors('enabled');
});

test('the company emails render', function () {
    $this->company->update(['status' => CompanyStatus::Declined, 'decision_note' => 'Tell us about your brands.']);

    expect((new CompanyRequested($this->company->id))->render())
        ->toContain('Himal Tea')
        ->toContain('mina@himaltea.test')
        ->toContain(route('socialhq.platform.companies.index'));

    expect((new CompanyApproved($this->company->id))->render())
        ->toContain('Himal Tea is approved')
        ->toContain(route('app.workspaces.create'));

    expect((new CompanyDeclined($this->company->id))->render())
        ->toContain('Tell us about your brands.')
        ->toContain('You can ask again after 24 hours.');
});
