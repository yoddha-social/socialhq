<?php

declare(strict_types=1);

use App\Enums\Repurpose\ItemStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Repurpose\PollRepurposeSource;
use App\Jobs\Repurpose\ProcessRepurposeItem;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Post\MediaAttacher;
use App\Services\Repurpose\CaptionAdapter;
use App\Services\Repurpose\SourceFetcherFactory;
use App\SocialHq\Companies\Company;
use App\SocialHq\Companies\CompanyStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['trypost.self_hosted' => true, 'socialhq.companies.required' => true]);
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Http::fake();
});

/**
 * An active repurpose watching an Instagram account of a company with this status.
 */
function repurposeOfCompany(CompanyStatus $status): Repurpose
{
    $company = Company::factory()->create(['status' => $status]);
    $workspace = Workspace::factory()->create(['account_id' => $company->account_id]);
    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    return Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
        'activated_at' => now()->subYear(),
    ]);
}

test('polling skips the repurposes of a paused company', function () {
    Bus::fake([PollRepurposeSource::class]);
    $paused = repurposeOfCompany(CompanyStatus::Suspended);
    $working = repurposeOfCompany(CompanyStatus::Approved);

    $this->artisan('repurpose:poll')->assertSuccessful();

    Bus::assertDispatched(PollRepurposeSource::class, fn (PollRepurposeSource $job): bool => $job->account->id === $working->source_social_account_id);
    Bus::assertNotDispatched(PollRepurposeSource::class, fn (PollRepurposeSource $job): bool => $job->account->id === $paused->source_social_account_id);
});

test('a paused company\'s source is not read, even by a poll already queued', function () {
    $repurpose = repurposeOfCompany(CompanyStatus::Suspended);

    (new PollRepurposeSource($repurpose->sourceAccount))->handle(app(SourceFetcherFactory::class));

    Http::assertNothingSent();
    expect($repurpose->items()->exists())->toBeFalse();
});

test('a paused company\'s queued item downloads nothing, makes no post and stays pending', function () {
    $item = RepurposeItem::factory()->for(repurposeOfCompany(CompanyStatus::Suspended))->create();

    (new ProcessRepurposeItem($item, 'https://93.184.216.34/v.mp4', 'Caption'))
        ->handle(app(MediaAttacher::class), app(CaptionAdapter::class));

    Http::assertNothingSent();
    expect($item->fresh()->status)->toBe(ItemStatus::Pending)
        ->and($item->posts()->exists())->toBeFalse();
});
