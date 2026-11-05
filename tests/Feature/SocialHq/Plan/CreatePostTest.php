<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefStage;
use App\Support\MediaImportStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create(['plan_id' => $plan->id, 'social_account_id' => $this->account->id, 'creative' => CreativeKind::Image]);
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'Happy Dashain', 'hashtags' => '#dashain']);
    $this->member = workspaceMember($this->workspace, 'approval');
});

function storedBriefFile(Brief $brief, int $round, string $name = 'final.png'): BriefFile
{
    $upload = UploadedFile::fake()->image($name, 120, 150);
    $path = Storage::putFileAs("socialhq/briefs/{$brief->id}", $upload, "{$round}-{$name}");

    return BriefFile::query()->create([
        'brief_id' => $brief->id,
        'round' => $round,
        'path' => $path,
        'original_name' => $name,
        'mime_type' => 'image/png',
        'size' => $upload->getSize(),
    ]);
}

test('create post imports the approved round into the workspace uploads and returns the composer draft', function () {
    $this->item->update(['copy_status' => CopyStatus::Approved]);
    $brief = Brief::factory()->for($this->item, 'item')->create(['stage' => BriefStage::Approved]);
    storedBriefFile($brief, 1, 'old.png');
    $final = storedBriefFile($brief, 2, 'final.png');

    $response = $this->actingAs($this->member)->postJson(route('socialhq.plan.items.post.store', $this->item->id))
        ->assertOk()
        ->assertJsonPath('content', "Happy Dashain\n\n#dashain")
        ->assertJsonPath('scheduled_on', $this->item->scheduled_on->toDateString())
        ->assertJsonPath('social_account_id', $this->account->id)
        ->assertJsonCount(1, 'media');

    $media = Media::query()->sole();

    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->original_filename)->toBe('final.png')
        ->and($media->upload_token)->not->toBeNull()
        ->and(data_get($media->meta, 'brief_file_id'))->toBe($final->id)
        ->and($response->json('media.0.upload_token'))->toBe($media->upload_token)
        ->and($this->item->fresh()->post_created_at)->not->toBeNull();

    $status = MediaImportStatus::find($response->json('import_ids.0'), $this->member->id, $this->workspace->id);

    expect($status['status'])->toBe(MediaImportStatus::DONE)
        ->and($status['media_id'])->toBe($media->id);
});

test('an item without a creative needs only approved copy', function () {
    $this->item->update(['creative' => CreativeKind::None, 'copy_status' => CopyStatus::Approved]);

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.post.store', $this->item->id))
        ->assertOk()
        ->assertJsonCount(0, 'media');

    expect(Media::query()->count())->toBe(0);
});

test('create post waits for approved copy and an approved creative', function () {
    $brief = Brief::factory()->for($this->item, 'item')->create(['stage' => BriefStage::InReview]);
    storedBriefFile($brief, 1);

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.post.store', $this->item->id))->assertUnprocessable()->assertJsonValidationErrors('post');

    $this->item->update(['copy_status' => CopyStatus::Approved]);
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.post.store', $this->item->id))->assertUnprocessable();

    expect(Media::query()->count())->toBe(0)
        ->and($this->item->fresh()->post_created_at)->toBeNull();
});

test('create post never reaches another workspace\'s item', function () {
    $other = PlanItem::factory()->create(['copy_status' => CopyStatus::Approved, 'creative' => CreativeKind::None]);
    ItemVersion::factory()->for($other, 'item')->create();

    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.post.store', $other->id))->assertNotFound();
});
