<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Enums\PostPlatform\ContentType;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\SocialHq\Ai\AiUnavailableException;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\GenerationStatus;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanOptions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks the AI planner for a month and saves what passes the checks as
 * suggestions, replacing the earlier run's suggestions (never planned or
 * dropped items). Every refused item is kept with its reason, and any AI
 * error leaves the plan "failed" with a message for the team.
 */
class GeneratePlanSuggestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $planId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $plan = ContentPlan::query()->with('workspace')->find($this->planId);

        if ($plan === null || $plan->generation_status !== GenerationStatus::Running) {
            return;
        }

        $user = User::query()->find($plan->generation_requested_by);

        if ($user === null || $plan->workspace === null || ! PlanAccess::canWork($user, $plan->workspace)) {
            $this->markFailed($plan, 'no_access');

            return;
        }

        try {
            $response = (new ContentPlanner($user->locale ?? Locale::DEFAULT))
                ->prompt(json_encode(PlannerContext::build($plan), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (AiUnavailableException $exception) {
            report($exception);
            $this->markFailed($plan, 'ai_unavailable');

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($plan, 'ai_failed');

            return;
        }

        $items = data_get($response, 'items');

        if (! is_array($items)) {
            $this->markFailed($plan, 'invalid_output');

            return;
        }

        [$accepted, $rejections] = $this->check($plan, array_slice(array_values($items), 0, ContentPlanner::MAX_ITEMS));

        DB::transaction(function () use ($plan, $accepted, $rejections, $response): void {
            $plan->items()->where('status', ItemStatus::Suggested)->delete();

            $positions = [];

            foreach ($accepted as $attributes) {
                $day = $attributes['scheduled_on'];
                $positions[$day] ??= (int) $plan->items()->whereDate('scheduled_on', $day)->max('position');
                $positions[$day]++;

                $plan->items()->create([...$attributes, 'position' => $positions[$day]]);
            }

            $plan->forceFill([
                'generation_status' => GenerationStatus::Idle,
                'generation_error' => null,
                'generation_summary' => Str::limit(trim((string) data_get($response, 'summary')), 2000, '…'),
                'generation_rejections' => $rejections,
                'generated_at' => now(),
                'generation_started_at' => null,
            ])->save();

            if ($accepted !== []) {
                $plan->reopen();
            }
        });
    }

    /**
     * A timeout or a crash outside handle(): the plan must not stay "running".
     */
    public function failed(?Throwable $exception): void
    {
        $plan = ContentPlan::query()->find($this->planId);

        if ($plan !== null && $plan->generation_status === GenerationStatus::Running) {
            $this->markFailed($plan, 'ai_failed');
        }
    }

    /**
     * @param  list<mixed>  $items
     * @return array{0: list<array<string, mixed>>, 1: list<array{title: string, date: string, channel: string, reason: string}>}
     */
    private function check(ContentPlan $plan, array $items): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $plan->month->toDateString(), 'UTC');
        $end = PlanMonth::end($month);
        $workspace = $plan->workspace;
        $profile = PlanOptions::profile($workspace);
        $pillars = PlanOptions::pillars($profile);
        $channels = PlanOptions::channels($workspace)->keyBy('id');
        $events = PlanOptions::events($workspace, $month, $end)->groupBy('key');

        /** @var array<string, true> $taken channel id + day already holding an item */
        $taken = $plan->items()
            ->where('status', ItemStatus::Planned)
            ->get(['social_account_id', 'scheduled_on'])
            ->mapWithKeys(fn (PlanItem $item): array => ["{$item->social_account_id}|{$item->scheduled_on->toDateString()}" => true])
            ->all();

        $accepted = [];
        $rejections = [];

        foreach ($items as $item) {
            $title = Str::limit(trim((string) data_get($item, 'title')), 160, '');
            $dateText = trim((string) data_get($item, 'date'));
            $channelId = trim((string) data_get($item, 'channel'));
            /** @var SocialAccount|null $channel */
            $channel = $channels->get($channelId);
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $dateText, 'UTC') : null;
            $contentType = ContentType::tryFrom(trim((string) data_get($item, 'content_type')));
            $eventKey = trim((string) data_get($item, 'event_key'));
            /** @var Collection<int, CalendarEvent>|null $matches */
            $matches = $eventKey === '' ? null : $events->get($eventKey);
            $event = $matches?->first(fn (CalendarEvent $candidate): bool => $candidate->isVerified());
            $slot = $channel === null || $date === null ? null : "{$channel->id}|{$date->toDateString()}";

            $reason = match (true) {
                $title === '' => 'missing_title',
                $date === null || $date->toDateString() !== $dateText || $date->lessThan($month) || $date->greaterThan($end) => 'date_outside_month',
                $channel === null => 'unknown_channel',
                $contentType === null || ! PlanOptions::fitsChannel($contentType, $channel->platform) => 'content_type_not_for_channel',
                $eventKey !== '' && $matches === null => 'unknown_event',
                $eventKey !== '' && $event === null => 'unconfirmed_event',
                isset($taken[$slot]) => 'duplicate_day',
                default => null,
            };

            if ($reason !== null) {
                $rejections[] = [
                    'title' => $title,
                    'date' => Str::limit($dateText, 20, ''),
                    'channel' => $channel?->display_label ?? Str::limit($channelId, 60, ''),
                    'reason' => $reason,
                ];

                continue;
            }

            $taken[$slot] = true;
            $pillar = trim((string) data_get($item, 'pillar'));

            $accepted[] = [
                'workspace_id' => $workspace->id,
                'scheduled_on' => $date->toDateString(),
                'social_account_id' => $channel->id,
                'platform' => $channel->platform->value,
                'content_type' => $contentType,
                'creative' => CreativeKind::tryFrom((string) data_get($item, 'creative')) ?? CreativeKind::Image,
                'pillar' => in_array($pillar, $pillars, true) ? $pillar : null,
                'title' => $title,
                'objective' => Str::limit(trim((string) data_get($item, 'objective')), 300, '') ?: null,
                'angle' => Str::limit(trim((string) data_get($item, 'angle')), 600, '') ?: null,
                'event_id' => $event?->id,
                'status' => ItemStatus::Suggested,
                'source' => ItemSource::Ai,
                'created_by' => $plan->generation_requested_by,
            ];
        }

        return [$accepted, $rejections];
    }

    private function markFailed(ContentPlan $plan, string $reason): void
    {
        $plan->forceFill([
            'generation_status' => GenerationStatus::Failed,
            'generation_error' => $reason,
            'generation_started_at' => null,
        ])->save();
    }
}
