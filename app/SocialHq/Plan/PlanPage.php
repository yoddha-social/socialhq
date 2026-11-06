<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Http\Controllers\Plan\PlanGenerationController;
use App\Support\Timezone;
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

        return [
            'month' => PlanMonth::key($month),
            'today' => CarbonImmutable::now(Timezone::normalize($user->timezone))->toDateString(),
            'plan' => $plan === null ? null : self::plan($plan),
            'items' => $plan === null ? [] : $plan->items()
                ->orderBy('scheduled_on')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (PlanItem $item): array => self::item($item))
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
            'event_id' => $item->event_id,
            'owner_id' => $item->owner_id,
            'status' => $item->status->value,
            'source' => $item->source->value,
            'copy_status' => $item->copy_status->value,
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
     * @return array<string, mixed>
     */
    private static function channel(SocialAccount $channel): array
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
