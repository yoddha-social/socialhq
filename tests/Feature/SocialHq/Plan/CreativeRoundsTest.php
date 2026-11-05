<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefStage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    // Production's default disk is a public bucket: brief files must never land there.
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create(['plan_id' => $plan->id]);
    $this->brief = Brief::factory()->for($this->item, 'item')->create();
    $this->designer = workspaceMember($this->workspace, 'approval');
});

function uploadCreative(object $test, User $user, ?UploadedFile $file = null, ?string $note = null): Illuminate\Testing\TestResponse
{
    return $test->actingAs($user)->post(route('socialhq.plan.items.brief.files.store', $test->item->id), [
        'file' => $file ?? UploadedFile::fake()->image('draft.png', 200, 250),
        'note' => $note,
    ]);
}

test('an upload is stored privately under the brief and starts work on it', function () {
    uploadCreative($this, $this->designer, note: 'First take')->assertSessionHasNoErrors();

    $file = BriefFile::query()->sole();

    expect($file->round)->toBe(1)
        ->and($file->path)->toStartWith("socialhq/briefs/{$this->brief->id}/")
        ->and($file->mime_type)->toBe('image/png')
        ->and($file->note)->toBe('First take')
        ->and($file->uploaded_by)->toBe($this->designer->id)
        ->and($this->brief->fresh()->stage)->toBe(BriefStage::InProgress);

    // On the private disk, never the default (public) one.
    Storage::disk('local')->assertExists($file->path);
    Storage::disk('public')->assertMissing($file->path);

    $this->actingAs($this->designer)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('brief.current_round', 1)
            ->where('brief.rounds.0.round', 1)
            ->where('brief.rounds.0.files.0.type', 'image')
            ->where('brief.rounds.0.files.0.url', route('socialhq.plan.items.brief.files.show', ['item' => $this->item->id, 'file' => $file->id])));
});

test('only allowed types and sizes are accepted', function () {
    uploadCreative($this, $this->designer, UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))->assertSessionHasErrors('file');
    uploadCreative($this, $this->designer, UploadedFile::fake()->create('clip.webm', 10, 'video/webm'))->assertSessionHasErrors('file');

    config(['trypost.media.max_size_mb.image' => 1]);
    uploadCreative($this, $this->designer, UploadedFile::fake()->image('huge.png')->size(2048))->assertSessionHasErrors('file');

    uploadCreative($this, $this->designer, UploadedFile::fake()->create('deck.pdf', 50, 'application/pdf'))->assertSessionHasNoErrors();

    expect(BriefFile::query()->pluck('mime_type')->all())->toBe(['application/pdf']);
});

test('files are served only to the workspace, through the app', function () {
    uploadCreative($this, $this->designer);
    $file = BriefFile::query()->sole();
    $url = route('socialhq.plan.items.brief.files.show', ['item' => $this->item->id, 'file' => $file->id]);

    $this->actingAs($this->designer)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

    $this->actingAs(workspaceOutsider($this->workspace))->get($url)->assertForbidden();

    $stranger = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);
    $otherWorkspace->members()->attach($stranger->id, membershipPivot('admin'));
    $stranger->update(['current_workspace_id' => $otherWorkspace->id]);
    $this->actingAs($stranger->fresh())->get($url)->assertNotFound();

    // A file id from another brief is not found under this item.
    $otherBrief = Brief::factory()->create();
    $foreign = BriefFile::query()->create(['brief_id' => $otherBrief->id, 'round' => 1, 'path' => 'x.png', 'original_name' => 'x.png', 'mime_type' => 'image/png', 'size' => 1]);
    $this->actingAs($this->designer)->get(route('socialhq.plan.items.brief.files.show', ['item' => $this->item->id, 'file' => $foreign->id]))->assertNotFound();
});

test('a round is reviewed: changes need a note, only approvers decide, the next upload starts a new round', function () {
    $this->actingAs($this->designer)->post(route('socialhq.plan.items.brief.submit', $this->item->id))->assertSessionHasErrors('creative');

    uploadCreative($this, $this->designer);
    $this->actingAs($this->designer)->post(route('socialhq.plan.items.brief.submit', $this->item->id))->assertSessionHasNoErrors();
    expect($this->brief->fresh()->stage)->toBe(BriefStage::InReview);

    // No uploads while in review.
    uploadCreative($this, $this->designer)->assertSessionHasErrors('file');

    $this->actingAs($this->designer)->post(route('socialhq.plan.items.brief.approve', $this->item->id))->assertForbidden();
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.request-changes', $this->item->id), ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.request-changes', $this->item->id), ['note' => 'Bigger logo'])->assertSessionHasNoErrors();
    expect($this->brief->fresh()->stage)->toBe(BriefStage::ChangesRequested);

    // The reviewed round can't be sent again without new files.
    $this->actingAs($this->designer)->post(route('socialhq.plan.items.brief.submit', $this->item->id))->assertSessionHasErrors('creative');

    uploadCreative($this, $this->designer, UploadedFile::fake()->image('v2.png'));
    uploadCreative($this, $this->designer, UploadedFile::fake()->image('v2-alt.png'));
    expect(BriefFile::query()->orderBy('id')->pluck('round')->all())->toBe([1, 2, 2])
        ->and($this->brief->fresh()->stage)->toBe(BriefStage::InProgress);

    $this->actingAs($this->designer)->post(route('socialhq.plan.items.brief.submit', $this->item->id));
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.approve', $this->item->id))->assertSessionHasNoErrors();

    $brief = $this->brief->fresh();
    $thread = ItemReview::query()->where('brief_id', $brief->id)->orderBy('id')->get();

    expect($brief->stage)->toBe(BriefStage::Approved)
        ->and($brief->approved_by)->toBe($this->owner->id)
        ->and($brief->approved_at)->not->toBeNull()
        ->and($thread->map(fn (ItemReview $review): string => "{$review->kind->value}:{$review->round}")->all())
        ->toBe(['submitted:1', 'changes_requested:1', 'submitted:2', 'approved:2'])
        ->and($thread->firstWhere('kind', ReviewKind::ChangesRequested)->body)->toBe('Bigger logo');

    // Approved creative takes no more uploads.
    uploadCreative($this, $this->designer)->assertSessionHasErrors('file');
});

test('uploads and reviews never reach another workspace\'s brief', function () {
    $other = Brief::factory()->create();

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.files.store', $other->item_id), ['file' => UploadedFile::fake()->image('a.png')])->assertNotFound();
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.submit', $other->item_id))->assertNotFound();
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.approve', $other->item_id))->assertNotFound();

    expect(BriefFile::query()->count())->toBe(0);
});

test('a file\'s stored name and served type come from its content, not its name', function () {
    // A real upload (not a fake, whose type follows its name), so the type is sniffed from the bytes.
    $path = tempnam(sys_get_temp_dir(), 'shq-test-');
    file_put_contents($path, UploadedFile::fake()->image('page.png', 120, 120)->getContent());
    $disguised = new UploadedFile($path, 'page.html', 'text/html', null, true);

    uploadCreative($this, $this->designer, $disguised)->assertSessionHasNoErrors();

    $file = BriefFile::query()->sole();

    expect($file->path)->toEndWith('.png')
        ->and($file->mime_type)->toBe('image/png')
        ->and($file->original_name)->toBe('page.html');

    $this->actingAs($this->designer)->get(route('socialhq.plan.items.brief.files.show', ['item' => $this->item->id, 'file' => $file->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('a round can\'t be decided unless it is in review with files', function () {
    $this->brief->update(['stage' => BriefStage::InReview]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.brief.approve', $this->item->id))->assertSessionHasErrors('creative');

    expect($this->brief->fresh()->stage)->toBe(BriefStage::InReview);
});

test('deleting an item removes its brief files from storage', function () {
    uploadCreative($this, $this->designer);
    $path = BriefFile::query()->sole()->path;

    $this->item->delete();

    Storage::disk('local')->assertMissing($path);
    expect(Brief::query()->count())->toBe(0);
});
