<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);

    $this->linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $draft = fn (SocialAccount $account, array $attributes = []): Post => Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        ...$attributes,
    ]);

    $draft($this->linkedin);
    $draft($this->linkedin);
    $draft($this->x);
    $draft($this->x, ['scheduled_at' => now()->addDays(3)]);
    $draft($this->x, ['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDays(3)]);
});

test('the calendar counts the drafts with no date only while the post list is open', function () {
    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'month']))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('undatedDraftsCount'));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'month', 'undated' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('undatedDraftsCount', 3));
});

test('the count of drafts with no date follows the channel filter', function () {
    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'undated' => 1, 'channels' => [$this->x->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('undatedDraftsCount', 1));

    $this->actingAs($this->user)
        ->get(route('app.channels.calendar', ['account' => $this->linkedin->id, 'view' => 'week', 'undated' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('undatedDraftsCount', 2));
});
