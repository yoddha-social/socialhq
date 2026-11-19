<?php

declare(strict_types=1);

use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\SendNotification;
use App\Mail\AccountDisconnected;
use App\Mail\PostApprovalRequested;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Notices\Notice;
use App\SocialHq\Notices\NoticeCreated;
use App\SocialHq\Notices\NoticeKind;
use App\SocialHq\Notices\Notices;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    Event::fake([NoticeCreated::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);

    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->post = Post::factory()->forAccount($this->channel)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
        'content' => 'Winter menu is here',
        'platform_url' => 'https://example.com/post/1',
    ]);

    $this->notify = fn (Type $type, $mailable, ?User $user = null) => (new SendNotification($user ?? $this->user, $type, $mailable))->handle();
});

test('a published post becomes a notice with a link to the post on the network, and tells the open tabs', function () {
    ($this->notify)(Type::PostPublished, new PostPublished($this->post));

    $notice = Notice::query()->sole();

    expect($notice->kind)->toBe(NoticeKind::PostPublished)
        ->and($notice->user_id)->toBe($this->user->id)
        ->and($notice->workspace_id)->toBe($this->workspace->id)
        ->and($notice->post_id)->toBe($this->post->id)
        ->and($notice->read_at)->toBeNull();
    Event::assertDispatched(NoticeCreated::class, fn (NoticeCreated $event): bool => $event->noticeId === $notice->id && $event->userId === $this->user->id);

    $this->actingAs($this->user)->getJson(route('socialhq.notices.index'))
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('notices.0.kind', 'post_published')
        ->assertJsonPath('notices.0.body', 'Winter menu is here')
        ->assertJsonPath('notices.0.read', false)
        ->assertJsonPath('notices.0.actions.0.href', 'https://example.com/post/1')
        ->assertJsonPath('notices.0.actions.0.external', true);
});

test('the notice is written even when the person turned the email off, and the email follows its own switch', function () {
    $this->user->notificationPreference()->updateOrCreate([], ['post_published' => false]);

    ($this->notify)(Type::PostPublished, new PostPublished($this->post));

    expect(Notice::query()->count())->toBe(1);
    Mail::assertNothingOutgoing();
});

test('turning a row off in the app stops its notices and leaves the email alone', function () {
    $this->actingAs($this->user)
        ->patchJson(route('socialhq.notices.preferences.update'), ['post_published' => false])
        ->assertOk()
        ->assertJsonPath('in_app.post_published', false)
        ->assertJsonPath('in_app.post_failed', true)
        ->assertJsonPath('live', true);

    ($this->notify)(Type::PostPublished, new PostPublished($this->post));
    ($this->notify)(Type::PostFailed, new PostPublishFailed($this->post));

    expect(Notice::query()->pluck('kind')->all())->toBe([NoticeKind::PostFailed]);
    Mail::assertQueued(PostPublished::class);
});

test('with live notices off the notice is written without telling the open tabs', function () {
    Notices::updatePreferences($this->user, ['live' => false]);

    ($this->notify)(Type::PostPublished, new PostPublished($this->post));

    expect(Notice::query()->count())->toBe(1);
    Event::assertNotDispatched(NoticeCreated::class);
});

test('a channel that lost its connection is mentioned once a day', function () {
    ($this->notify)(Type::AccountDisconnected, new AccountDisconnected($this->channel));
    ($this->notify)(Type::AccountDisconnected, new AccountDisconnected($this->channel));

    expect(Notice::query()->where('kind', NoticeKind::ChannelDisconnected)->count())->toBe(1);

    $this->travel(Notices::DISCONNECTED_EVERY_HOURS + 1)->hours();
    ($this->notify)(Type::AccountDisconnected, new AccountDisconnected($this->channel));

    expect(Notice::query()->where('kind', NoticeKind::ChannelDisconnected)->count())->toBe(2);

    $this->actingAs($this->user)->getJson(route('socialhq.notices.index'))
        ->assertJsonPath('notices.0.actions.0.href', route('app.channels.publish', $this->channel->id));
});

test('an approval request sends the approver to Waiting for you, and is dropped once the post is no longer pending', function () {
    $requester = User::factory()->create();
    $pending = Post::factory()->forAccount($this->channel)->create(['user_id' => $requester->id, 'status' => PostStatus::PendingApproval]);

    ($this->notify)(Type::Collaboration, new PostApprovalRequested($pending->id, $requester, $this->user));
    ($this->notify)(Type::Collaboration, new PostApprovalRequested($this->post->id, $requester, $this->user));

    expect(Notice::query()->pluck('post_id')->all())->toBe([$pending->id]);

    $this->actingAs($this->user)->getJson(route('socialhq.notices.index'))
        ->assertJsonPath('notices.0.kind', 'approval_requested')
        ->assertJsonPath('notices.0.actions.0.href', route('socialhq.inbox'));
});

test('marking read clears the count, one notice or all of them, and only the reader\'s own', function () {
    $other = User::factory()->create();
    $this->workspace->members()->attach($other->id, membershipPivot('member'));

    ($this->notify)(Type::PostPublished, new PostPublished($this->post));
    ($this->notify)(Type::PostFailed, new PostPublishFailed($this->post));
    ($this->notify)(Type::PostPublished, new PostPublished($this->post), $other);

    $first = Notice::query()->where('user_id', $this->user->id)->oldest('id')->firstOrFail();

    $this->actingAs($this->user)->postJson(route('socialhq.notices.read'), ['ids' => [$first->id]])
        ->assertOk()
        ->assertJsonPath('unread', 1);
    $this->actingAs($this->user)->postJson(route('socialhq.notices.read'))
        ->assertOk()
        ->assertJsonPath('unread', 0);

    expect(Notice::query()->where('user_id', $other->id)->whereNull('read_at')->count())->toBe(1);
});

test('the bell lists the last week of the current workspace, and old notices are pruned after a month', function () {
    ($this->notify)(Type::PostPublished, new PostPublished($this->post));
    $this->travel(Notice::LISTED_DAYS + 1)->days();
    ($this->notify)(Type::PostFailed, new PostPublishFailed($this->post));

    $this->actingAs($this->user)->getJson(route('socialhq.notices.index'))
        ->assertJsonCount(1, 'notices')
        ->assertJsonPath('notices.0.kind', 'post_failed');

    $this->travel(Notice::RETENTION_DAYS + 1)->days();
    $this->artisan('model:prune', ['--model' => [Notice::class]]);

    expect(Notice::query()->count())->toBe(0);
});

test('the shared props carry the unread count and the person\'s private channel', function () {
    ($this->notify)(Type::PostPublished, new PostPublished($this->post));

    $this->actingAs($this->user)->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page
            ->where('socialhq.notices.unread', 1)
            ->where('socialhq.notices.channel', NoticeCreated::channelFor($this->user->id)));
});

test('a retried email writes its notice once', function () {
    $job = new SendNotification($this->user, Type::PostPublished, new PostPublished($this->post));
    $job->handle();

    $retried = Mockery::mock(SendNotification::class, [$this->user, Type::PostPublished, new PostPublished($this->post)])->makePartial();
    $retried->shouldReceive('attempts')->andReturn(2);
    $retried->handle();

    expect(Notice::query()->count())->toBe(1);
});
