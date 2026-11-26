<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->channels = SocialAccount::factory()->linkedin()->count(2)->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);
});

/**
 * What the composer sends for a post on these channels.
 *
 * @param  iterable<SocialAccount>  $channels
 * @return array<string, mixed>
 */
function composerSavedStore(iterable $channels, PostStatus $status): array
{
    return [
        'status' => $status->value,
        'content' => 'Winter menu is here',
        'media' => [],
        'scheduled_at' => now()->addDays(3)->startOfMinute()->toIso8601String(),
        'destinations' => collect($channels)->map(fn (SocialAccount $channel): array => [
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ])->all(),
    ];
}

test('saving drafts says how many were saved', function () {
    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerSavedStore($this->channels, PostStatus::Draft))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.success', trans_choice('socialhq.composer.saved.draft', 2, ['count' => 2]));

    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerSavedStore([$this->channels->first()], PostStatus::Draft))
        ->assertSessionHas('flash.success', trans_choice('socialhq.composer.saved.draft', 1, ['count' => 1]));
});

test('a member who needs approval hears the posts went for approval', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)
        ->post(route('app.posts.store'), composerSavedStore([$this->channels->first()], PostStatus::Scheduled))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.success', trans_choice('socialhq.composer.saved.pending_approval', 1, ['count' => 1]));

    expect(Post::query()->sole()->status)->toBe(PostStatus::PendingApproval);
});

test('scheduling directly shows the post where it went, with no toast', function () {
    $this->actingAs($this->owner)
        ->post(route('app.posts.store'), composerSavedStore([$this->channels->first()], PostStatus::Scheduled))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('flash.success');
});

test('moving a scheduled post back to drafts in the composer says it was saved', function () {
    $post = Post::factory()->forAccount($this->channels->first())->create([
        'user_id' => $this->owner->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDays(2)->startOfMinute(),
    ]);

    $this->actingAs($this->owner)
        ->put(route('app.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'content' => 'Winter menu is here',
            'media' => [],
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.success', trans_choice('socialhq.composer.saved.draft', 1, ['count' => 1]));

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});
