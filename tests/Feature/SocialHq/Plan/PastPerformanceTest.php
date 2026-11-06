<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\PastPerformance;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
});

/**
 * A published post of the workspace with its latest metrics.
 *
 * @param  array<string, mixed>  $metrics
 * @param  array<string, mixed>  $attributes
 */
function pastPost(Workspace $workspace, array $metrics, array $attributes = []): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id, ...$attributes]);
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, ...$metrics]);

    return $publication;
}

test('the best three and weakest two posts are ranked by engagement', function () {
    $posts = collect([50, 10, 90, 30, 70, 20])->mapWithKeys(fn (int $engagement, int $index): array => [
        $engagement => pastPost($this->workspace, ['engagement_count' => $engagement, 'reach_count' => 1000 - $engagement], ['provider_published_at' => now()->subDays($index + 1)]),
    ]);
    pastPost($this->workspace, ['reach_count' => 5000]);

    $lately = PastPerformance::lately($this->workspace->id);

    expect($lately['metric'])->toBe('engagement')
        ->and(array_column($lately['best'], 'value'))->toBe([90, 70, 50])
        ->and(array_column($lately['weakest'], 'value'))->toBe([10, 20])
        ->and($lately['best'][0]['id'])->toBe($posts[90]->id)
        ->and($lately['best'][0])->toMatchArray([
            'platform' => $posts[90]->platform->value,
            'content_type' => 'image',
            'channel' => $posts[90]->account_display_name,
            'avatar_url' => $posts[90]->account_avatar_url,
            'url' => $posts[90]->permalink,
            'date' => $posts[90]->provider_published_at->utc()->toDateString(),
        ]);
});

test('without any engagement the posts are ranked by reach, then by views', function () {
    pastPost($this->workspace, ['reach_count' => 300]);
    pastPost($this->workspace, ['reach_count' => 900, 'views_count' => 5]);
    pastPost($this->workspace, ['views_count' => 10000]);

    $lately = PastPerformance::lately($this->workspace->id);

    expect($lately['metric'])->toBe('reach')
        ->and(array_column($lately['best'], 'value'))->toBe([900, 300])
        ->and($lately['weakest'])->toBe([]);

    AnalyticsPublicationDailySnapshot::query()->update(['reach_count' => null]);

    expect(PastPerformance::lately($this->workspace->id)['metric'])->toBe('views');
});

test('a post links to its post on the network, or to the publication\'s link', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $post->forceFill(['platform_url' => 'https://www.instagram.com/p/from-the-post'])->save();
    pastPost($this->workspace, ['engagement_count' => 5], ['post_id' => $post->id, 'excerpt' => null, 'permalink' => 'https://www.instagram.com/p/from-analytics']);

    expect(PastPerformance::lately($this->workspace->id)['best'][0]['url'])->toBe('https://www.instagram.com/p/from-the-post');
});

test('the best format is the one with the best average among formats with two posts or more', function () {
    pastPost($this->workspace, ['engagement_count' => 400], ['content_type' => PublicationContentType::Carousel]);
    pastPost($this->workspace, ['engagement_count' => 100], ['content_type' => PublicationContentType::Reel]);
    pastPost($this->workspace, ['engagement_count' => 200], ['content_type' => PublicationContentType::Reel]);
    pastPost($this->workspace, ['engagement_count' => 60], ['content_type' => PublicationContentType::Image]);
    pastPost($this->workspace, ['engagement_count' => 40], ['content_type' => PublicationContentType::Image]);

    expect(PastPerformance::lately($this->workspace->id)['best_format'])->toBe(['content_type' => 'reel', 'average' => 150, 'posts' => 2]);
});

test('only the workspace\'s posts of the last 90 days count', function () {
    $recent = pastPost($this->workspace, ['engagement_count' => 10], ['provider_published_at' => now()->subDays(PastPerformance::DAYS - 1)]);
    pastPost($this->workspace, ['engagement_count' => 999], ['provider_published_at' => now()->subDays(PastPerformance::DAYS + 1)]);
    pastPost(Workspace::factory()->create(), ['engagement_count' => 500]);

    $lately = PastPerformance::lately($this->workspace->id);

    expect(array_column($lately['best'], 'id'))->toBe([$recent->id])
        ->and($lately['best_format'])->toBeNull();
});

test('a brand without measured posts gets nothing to show', function () {
    pastPost($this->workspace, []);

    expect(PastPerformance::lately($this->workspace->id))->toBe(['metric' => null, 'best' => [], 'weakest' => [], 'best_format' => null]);
});

test('the month page sends what worked lately only when the panel asks for it', function (string $access) {
    $viewer = $access === 'owner' ? $this->owner : workspaceMember($this->workspace, $access);
    pastPost($this->workspace, ['engagement_count' => 42]);
    $month = CarbonImmutable::now('UTC')->format('Y-m');

    $this->actingAs($viewer)
        ->get(route('socialhq.plan.show', $month))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('socialhq/plan/Month')
            ->missing('whatWorked')
            ->reloadOnly('whatWorked', fn (Assert $reload) => $reload
                ->where('whatWorked.metric', 'engagement')
                ->where('whatWorked.best.0.value', 42)));
})->with(['owner', 'approval']);
