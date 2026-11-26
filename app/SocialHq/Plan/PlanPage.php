<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Http\Controllers\Plan\PlanGenerationController;
use App\SocialHq\Inbox\WaitingForYou;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Knowledge\Audience\AudienceCountries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The props of the month page: the plan, its items, the month's events and
 * what an item can be made of (channels, formats, owners, pillars, cadence).
 */
final class PlanPage
{
    /**
     * @return array<string, mixed>
     */
    public static function props(User $user, Workspace $workspace, CarbonImmutable $month): array
    {
        $plan = PlanMonth::find($workspace, $month);
        $profile = PlanOptions::profile($workspace);
        $channels = PlanOptions::channels($workspace);
        $abilities = PlanAccess::abilities($user, $workspace);
        $aiAvailable = AiRouter::isAvailable(AiTask::Structured) && Gate::forUser($user)->allows('useAi', $workspace->account);
        $movesNeedApproval = ItemPosts::movesNeedApproval($workspace, $user);

        return [
            'month' => PlanMonth::key($month),
            // Months with a plan, copy or creative waiting for this person, marked in the month picker.
            // A closure: the page's 3-second poll asks for plan and items only.
            'waitingMonths' => fn (): array => WaitingForYou::months($user, $workspace),
            'today' => PlanMonth::today($user),
            'plan' => $plan === null ? null : self::plan($plan),
            'items' => $plan === null ? [] : $plan->items()
                ->with('posts:posts.id,posts.workspace_id,posts.status,posts.scheduled_at,posts.social_account_id,posts.platform,posts.platform_name', 'posts.socialAccount')
                ->orderBy('scheduled_on')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (PlanItem $item): array => [
                    ...self::item($item),
                    // The times of its approved posts that "Move posts too" would send back for approval (the offer warns first).
                    'posts_back_to_approval' => ItemPosts::backToApprovalOnMove($item, $user, $movesNeedApproval)
                        ->map(fn (Post $post): string => $post->scheduled_at->toIso8601String())
                        ->all(),
                ])
                ->all(),
            'events' => PlanOptions::events($workspace, $month, PlanMonth::end($month))
                ->map(fn (CalendarEvent $event): array => self::event($event))
                ->all(),
            // Not 'channels': that name is the sidebar's shared prop.
            'planChannels' => $channels->map(fn (SocialAccount $channel): array => self::channel($channel))->all(),
            'owners' => PlanOptions::owners($workspace)->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $person->name,
                'photo_url' => $person->photo_url,
            ])->all(),
            'pillars' => PlanOptions::pillars($profile),
            'cadence' => PlanOptions::cadence($profile, $channels),
            'creatives' => array_map(fn (CreativeKind $kind): string => $kind->value, CreativeKind::cases()),
            'abilities' => [...$abilities, 'canGenerate' => $abilities['canWork'] && $aiAvailable],
            'ai' => ['available' => $aiAvailable, 'hourlyLimit' => PlanGenerationController::HOURLY_LIMIT],
            // Posting times are written in the main audience zone and shown in the viewer's too.
            'audience' => [
                'mainTimezone' => Audience::mainZone($profile, $user->timezone),
                'countries' => array_map(fn (string $code): array => ['code' => $code, 'name' => AudienceCountries::name($code)], Audience::countries($profile)),
            ],
            // What "Generate with AI" opens with: the brand's last switches and the month's days.
            'generateDefaults' => PlannerOptions::defaults($month, $profile)->toArray(),
            // Asked for by a partial reload when the "What worked lately" panel opens.
            'whatWorked' => Inertia::optional(fn (): array => PastPerformance::lately($workspace->id)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(PlanItem $item): array
    {
        return [
            'id' => $item->id,
            'scheduled_on' => $item->scheduled_on->toDateString(),
            'social_account_id' => $item->social_account_id,
            'platform' => $item->platform,
            'content_type' => $item->content_type?->value,
            'content_type_label' => $item->content_type?->label(),
            'creative' => $item->creative->value,
            'pillar' => $item->pillar,
            'title' => $item->title,
            'objective' => $item->objective,
            'angle' => $item->angle,
            'unsupported_details' => array_values((array) $item->unsupported_details),
            'event_id' => $item->event_id,
            'owner_id' => $item->owner_id,
            'suggested_time' => $item->suggested_time,
            'suggested_timezone' => $item->suggested_timezone,
            'suggested_at' => SuggestedTime::instant($item)?->toIso8601ZuluString(),
            'status' => $item->status->value,
            'source' => $item->source->value,
            'copy_status' => $item->copy_status->value,
            // Where the item's posts stand (ItemPosts::shownStatus), or null without one.
            'post_status' => $item->relationLoaded('posts') ? ItemPosts::shownStatus($item->posts->pluck('status')) : null,
            // Posts that will still go out (the drop and delete dialog names them), and how many aren't on the item's day.
            'upcoming_posts' => $item->relationLoaded('posts') ? ItemPosts::upcomingForPage($item) : [],
            'posts_off_day' => $item->relationLoaded('posts') ? ItemPosts::offDayCount($item) : 0,
            'position' => $item->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function plan(ContentPlan $plan): array
    {
        $running = $plan->isGenerating();
        $stale = $plan->generation_status === GenerationStatus::Running && ! $running;
        $status = $stale ? GenerationStatus::Failed : $plan->generation_status;
        $error = $stale ? 'stale' : $plan->generation_error;

        return [
            'id' => $plan->id,
            'status' => $plan->status->value,
            'goals' => $plan->goals,
            'generation' => [
                'status' => $status->value,
                'error' => $status === GenerationStatus::Failed && filled($error) ? __("socialhq_plan.generation.errors.{$error}") : null,
                'summary' => $plan->generation_summary,
                'rejections' => array_values((array) ($plan->generation_rejections ?? [])),
                'generated_at' => $plan->generated_at?->toIso8601String(),
            ],
            'submitted_at' => $plan->submitted_at?->toIso8601String(),
            'approved_at' => $plan->approved_at?->toIso8601String(),
            'approver' => $plan->approver?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'key' => $event->key,
            'name' => $event->name,
            'category' => $event->category->value,
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'confirmed' => $event->isVerified(),
        ];
    }

    /**
     * A channel as the plan pages show it, with the formats it can post.
     *
     * @return array<string, mixed>
     */
    public static function channel(SocialAccount $channel): array
    {
        return [
            'id' => $channel->id,
            'platform' => $channel->platform->value,
            'name' => $channel->display_label,
            'avatar_url' => $channel->avatar_url,
            'content_types' => array_map(fn (ContentType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], PlanOptions::contentTypesFor($channel->platform)),
        ];
    }
}
