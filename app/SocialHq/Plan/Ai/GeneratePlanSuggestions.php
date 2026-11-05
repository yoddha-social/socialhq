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
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Throwable;

/**
 * Asks the AI planner for a month and saves what passes the checks as
 * suggestions, replacing the earlier run's suggestions (never planned or
 * dropped items). Every refused item is kept with its reason, and any AI
 * error leaves the plan "failed" with a message for the team. A job whose
 * run is no longer the plan's current one writes nothing.
 */
class GeneratePlanSuggestions implements ShouldQueue
{
    use Queueable;

    /** How many days before an event its post may go out. */
    public const EVENT_LEAD_DAYS = 3;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * @param  string  $runId  the plan's generation_run_id when the run started
     */
    public function __construct(public int $planId, public string $runId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $plan = ContentPlan::query()->with('workspace')->find($this->planId);

        if ($plan === null || ! $this->isCurrentRun($plan)) {
            return;
        }

        $user = User::query()->find($plan->generation_requested_by);

        if ($user === null || $plan->workspace === null || ! PlanAccess::canWork($user, $plan->workspace)) {
            $this->markFailed('no_access');

            return;
        }

        $today = CarbonImmutable::createFromFormat('!Y-m-d', CarbonImmutable::now(Timezone::normalize($user->timezone))->toDateString(), 'UTC');

        try {
            $response = (new ContentPlanner($user->locale ?? Locale::DEFAULT))
                ->prompt(json_encode(PlannerContext::build($plan, $today), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (AiUnavailableException $exception) {
            report($exception);
            $this->markFailed('ai_unavailable');

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed('ai_failed');

            return;
        }

        $items = data_get($response, 'items');

        if (! is_array($items)) {
            report(new RuntimeException(self::describeUnreadable($response)));
            $this->markFailed('invalid_output');

            return;
        }

        DB::transaction(function () use ($items, $response, $today): void {
            // Read again under a lock: an approval, an item change or a newer run may have come while the AI worked.
            $plan = ContentPlan::query()->with('workspace')->lockForUpdate()->find($this->planId);

            if ($plan === null || ! $this->isCurrentRun($plan)) {
                return;
            }

            [$accepted, $rejections] = $this->check($plan, array_slice(array_values($items), 0, ContentPlanner::MAX_ITEMS), $today);

            // Only untouched suggestions are replaced; one with copy or a brief keeps its work
            // (starting work accepts it, but older rows may predate that).
            $plan->items()->where('status', ItemStatus::Suggested)->whereDoesntHave('versions')->whereDoesntHave('brief')->delete();

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
                'generation_run_id' => null,
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
        $this->markFailed('ai_failed');
    }

    /**
     * @param  list<mixed>  $items
     * @return array{0: list<array<string, mixed>>, 1: list<array{title: string, date: string, channel: string, reason: string}>}
     */
    private function check(ContentPlan $plan, array $items, CarbonImmutable $today): array
    {
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $plan->month->toDateString(), 'UTC');
        $end = PlanMonth::end($month);
        $workspace = $plan->workspace;
        $profile = PlanOptions::profile($workspace);
        $pillars = PlanOptions::pillars($profile);
        $channelList = PlanOptions::channels($workspace);
        $channels = $channelList->keyBy('id');
        $cadence = PlanOptions::cadence($profile, $channelList);
        $events = PlanOptions::events($workspace, $month, $end)->groupBy('key');
        // Planned items, plus suggestions someone started work on (they survive a new draft).
        $planned = $plan->items()
            ->where(fn ($query) => $query->where('status', ItemStatus::Planned)
                ->orWhere(fn ($query) => $query->where('status', ItemStatus::Suggested)->where(fn ($query) => $query->has('versions')->orHas('brief'))))
            ->get(['social_account_id', 'scheduled_on', 'title']);

        /** @var array<string, true> $taken channel id + day already holding an item */
        $taken = $planned
            ->mapWithKeys(fn (PlanItem $item): array => ["{$item->social_account_id}|{$item->scheduled_on->toDateString()}" => true])
            ->all();

        /** @var array<string, true> $titles titles already in the month, compared without case */
        $titles = $planned->mapWithKeys(fn (PlanItem $item): array => [self::titleKey($item->title) => true])->all();

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
            $creative = CreativeKind::tryFrom(trim((string) data_get($item, 'creative')));
            $eventKey = trim((string) data_get($item, 'event_key'));
            /** @var Collection<int, CalendarEvent>|null $matches */
            $matches = $eventKey === '' ? null : $events->get($eventKey);
            $confirmed = $matches?->filter(fn (CalendarEvent $candidate): bool => $candidate->isVerified());
            $event = $date === null ? null : $confirmed?->first(fn (CalendarEvent $candidate): bool => self::fitsEvent($date, $candidate));
            $slot = $channel === null || $date === null ? null : "{$channel->id}|{$date->toDateString()}";

            $reason = match (true) {
                $title === '' => 'missing_title',
                $date === null || $date->toDateString() !== $dateText || $date->lessThan($month) || $date->greaterThan($end) => 'date_outside_month',
                $date->lessThan($today) => 'date_in_past',
                $channel === null => 'unknown_channel',
                data_get($cadence, $channel->id) === 0 => 'channel_paused',
                $contentType === null || ! PlanOptions::fitsChannel($contentType, $channel->platform) => 'content_type_not_for_channel',
                $creative === null || ! PlanOptions::creativeFits($creative, $contentType) => 'creative_mismatch',
                $eventKey !== '' && $matches === null => 'unknown_event',
                $eventKey !== '' && $confirmed->isEmpty() => 'unconfirmed_event',
                $eventKey !== '' && $event === null => 'event_date_mismatch',
                isset($titles[self::titleKey($title)]) => 'duplicate_title',
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
            $titles[self::titleKey($title)] = true;
            $pillar = trim((string) data_get($item, 'pillar'));

            $accepted[] = [
                'workspace_id' => $workspace->id,
                'scheduled_on' => $date->toDateString(),
                'social_account_id' => $channel->id,
                'platform' => $channel->platform->value,
                'content_type' => $contentType,
                'creative' => $creative,
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

    /**
     * An event's post goes on one of its days or up to EVENT_LEAD_DAYS before it.
     */
    private static function fitsEvent(CarbonImmutable $date, CalendarEvent $event): bool
    {
        $from = $event->starts_on->subDays(self::EVENT_LEAD_DAYS)->toDateString();

        return $date->toDateString() >= $from && $date->toDateString() <= $event->ends_on->toDateString();
    }

    /**
     * What the log needs to tell why an answer had no items: who answered, how
     * it stopped (a length stop means the output budget ran out) and its size.
     */
    private static function describeUnreadable(mixed $response): string
    {
        if (! $response instanceof TextResponse) {
            return 'Content plan draft without items: unexpected response '.get_debug_type($response);
        }

        $finish = $response->steps->last()?->finishReason->value ?? 'unknown';

        return sprintf(
            'Content plan draft without items: provider %s, model %s, finish reason %s, %d characters of text, %d output tokens.',
            $response->meta->provider ?? 'unknown',
            $response->meta->model ?? 'unknown',
            $finish,
            mb_strlen($response->text),
            $response->usage->completionTokens,
        );
    }

    private static function titleKey(string $title): string
    {
        return Str::lower(Str::squish($title));
    }

    private function isCurrentRun(ContentPlan $plan): bool
    {
        return $plan->generation_status === GenerationStatus::Running && $plan->generation_run_id === $this->runId;
    }

    /**
     * Ends this run as failed, unless the plan has moved on to another run since.
     */
    private function markFailed(string $reason): void
    {
        DB::transaction(function () use ($reason): void {
            $plan = ContentPlan::query()->lockForUpdate()->find($this->planId);

            if ($plan === null || ! $this->isCurrentRun($plan)) {
                return;
            }

            $plan->forceFill([
                'generation_status' => GenerationStatus::Failed,
                'generation_error' => $reason,
                'generation_started_at' => null,
                'generation_run_id' => null,
            ])->save();
        });
    }
}
