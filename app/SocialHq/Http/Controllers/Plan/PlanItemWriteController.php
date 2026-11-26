<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\Plan\DestroyPlanItemRequest;
use App\SocialHq\Http\Requests\Plan\DropPlanItemRequest;
use App\SocialHq\Http\Requests\Plan\MovePlanItemRequest;
use App\SocialHq\Http\Requests\Plan\StorePlanItemRequest;
use App\SocialHq\Http\Requests\Plan\UpdatePlanItemRequest;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Plan\Ai\UnsupportedDetails;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanChanged;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\PlanOptions;
use App\SocialHq\Production\BriefStage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes to the items of a month's plan from the month page: add, edit,
 * move, accept or drop a suggestion, restore, delete. Any change to an
 * approved plan sends it back for review; editing or moving an AI
 * suggestion accepts it, so a new AI draft never replaces that work. The
 * item page (copy, review, brief) has its own controller.
 *
 * Dropping or deleting an item whose post will still go out, or giving it
 * another channel or format, needs the person's choice for that post
 * ("posts": back to drafts, or keep it scheduled); without one nothing
 * changes, so a post scheduled since the page loaded never goes out
 * silently for an item that no longer matches it.
 */
class PlanItemWriteController extends Controller
{
    public function store(StorePlanItemRequest $request, string $month): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);
        $plan = PlanMonth::findOrCreate($workspace, PlanMonth::parse($month, $request->user()), $request->user());
        $channel = SocialAccount::query()->where('workspace_id', $workspace->id)->findOrFail($request->validated('social_account_id'));

        DB::transaction(function () use ($request, $plan, $workspace, $channel): void {
            $plan->items()->create([
                ...self::withTimeZone($request->validated(), null, $workspace, $request->user()),
                'workspace_id' => $workspace->id,
                'platform' => $channel->platform->value,
                'status' => ItemStatus::Planned,
                'source' => ItemSource::Manual,
                'position' => $this->nextPosition($plan, $request->validated('scheduled_on')),
                'created_by' => $request->user()->id,
            ]);

            $plan->reopen();
        });

        return back();
    }

    public function update(UpdatePlanItemRequest $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $channel = SocialAccount::query()->where('workspace_id', $found->workspace_id)->findOrFail($request->validated('social_account_id'));
        $kept = 0;

        $this->change($found, function (PlanItem $locked) use ($request, $channel, &$kept): void {
            // A post made for the old channel or format would still go out as it is.
            if ($locked->social_account_id !== $channel->id || $locked->content_type?->value !== $request->validated('content_type')) {
                $kept = $this->settleUpcomingPosts($locked, $request->validated('posts'), $request->user());
            }

            $locked->update([
                ...self::withTimeZone(Arr::except($request->validated(), 'posts'), $locked, $locked->plan->workspace, $request->user()),
                'platform' => $channel->platform->value,
            ]);

            $formatChanged = $locked->wasChanged(['social_account_id', 'content_type']);
            $creativeChanged = $locked->wasChanged(['creative', 'social_account_id', 'content_type']);
            $briefChanged = $locked->wasChanged(['objective', 'angle']);

            // Copy approved (or in review) for one channel and format isn't approved for another.
            if ($formatChanged) {
                ItemCopy::reopen($locked, $request->user(), __('socialhq_production.review.reopened_format'));
            }

            // A text-only post was allowed for what the item was; a new creative, channel or format waits again.
            if ($creativeChanged && $locked->creative_waived_at !== null) {
                $locked->forceFill(['creative_waived_at' => null, 'creative_waived_by' => null])->save();
            }

            // The planner's unchecked details stay flagged while the text still says them.
            if ($briefChanged && $locked->unsupported_details !== null) {
                $locked->update(['unsupported_details' => UnsupportedDetails::stillIn($locked->unsupported_details, "{$locked->objective}\n{$locked->angle}")]);
            }

            $locked->acceptForWork();
        });

        self::sayKeptPosts($kept);

        return back();
    }

    public function move(MovePlanItemRequest $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $day = $request->validated('scheduled_on');

        if ($found->scheduled_on->toDateString() !== $day) {
            $this->change($found, function (PlanItem $locked) use ($day): void {
                $locked->update([
                    'scheduled_on' => $day,
                    'position' => $this->nextPosition($locked->plan, $day),
                ]);
                $locked->acceptForWork();
            });
        }

        return back();
    }

    /**
     * An AI suggestion becomes part of the plan, unless its day has passed:
     * it can be moved to a coming day (which accepts it) or dropped.
     */
    public function accept(Request $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);

        $this->change($found, function (PlanItem $locked) use ($request): void {
            $this->ensureStatus($locked, ItemStatus::Suggested);

            if ($locked->scheduled_on->toDateString() < PlanMonth::today($request->user())) {
                throw ValidationException::withMessages(['item' => __('socialhq_plan.errors.suggestion_past')]);
            }

            $locked->update(['status' => ItemStatus::Planned]);
        });

        return back();
    }

    public function drop(DropPlanItemRequest $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $kept = 0;

        $this->change($found, function (PlanItem $locked) use ($request, &$kept): void {
            $this->ensureStatus($locked, ItemStatus::Suggested, ItemStatus::Planned);
            $kept = $this->settleUpcomingPosts($locked, $request->validated('posts'), $request->user());
            $locked->update(['status' => ItemStatus::Dropped]);
        });

        self::sayKeptPosts($kept);

        return back();
    }

    /**
     * A dropped AI suggestion nobody started work on comes back as an open
     * suggestion, to accept or drop again; anything else comes back planned.
     */
    public function restore(Request $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);

        $this->change($found, function (PlanItem $locked): void {
            $this->ensureStatus($locked, ItemStatus::Dropped);

            $untouchedSuggestion = $locked->source === ItemSource::Ai
                && ! $locked->versions()->exists()
                && ! $locked->brief()->exists();

            $locked->update(['status' => $untouchedSuggestion ? ItemStatus::Suggested : ItemStatus::Planned]);
        });

        return back();
    }

    /**
     * Approved copy, an approved creative or a post made from the item is
     * approved work: only approvers delete an item that has one. Others drop
     * it instead. A post counts while it is linked (a deleted post doesn't).
     */
    public function destroy(DestroyPlanItemRequest $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $kept = 0;

        $this->change($found, function (PlanItem $locked) use ($request, &$kept): void {
            $locked->loadMissing('brief');

            $approvedWork = $locked->copy_status === CopyStatus::Approved
                || $locked->brief?->stage === BriefStage::Approved
                || $locked->hasPosts();

            if ($approvedWork && ! PlanAccess::canApprove($request->user(), $locked->plan->workspace)) {
                throw ValidationException::withMessages(['item' => __('socialhq_plan.errors.delete_approved')]);
            }

            $kept = $this->settleUpcomingPosts($locked, $request->validated('posts'), $request->user());
            $locked->delete();
        });

        self::sayKeptPosts($kept);

        return back();
    }

    /**
     * Every open AI suggestion of the month on a coming day becomes part of
     * the plan.
     */
    public function acceptAll(Request $request, string $month): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);
        $plan = PlanMonth::find($workspace, PlanMonth::parse($month, $request->user()));

        if ($plan !== null) {
            DB::transaction(function () use ($plan, $request): void {
                // A suggestion on a day already gone stays open: it would add an item to the past.
                $accepted = $plan->items()
                    ->where('status', ItemStatus::Suggested)
                    ->whereDate('scheduled_on', '>=', PlanMonth::today($request->user()))
                    ->update(['status' => ItemStatus::Planned, 'updated_at' => now()]);
                PlanChanged::dispatchFor($plan->id);

                if ($accepted > 0) {
                    $plan->reopen();
                }
            });
        }

        return back();
    }

    /**
     * One all-or-nothing change to an item, under its row lock: two people
     * acting on it at once take turns, and the second one's checks see what
     * the first did. Posts sent back to drafts on the way stay scheduled if
     * the change fails; their jobs and events go out after the commit
     * (TryPost's post actions queue them so).
     *
     * @param  callable(PlanItem): void  $write
     */
    private function change(PlanItem $item, callable $write): void
    {
        DB::transaction(function () use ($item, $write): void {
            $locked = PlanItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $write($locked);
            $locked->plan->reopen();
        });
    }

    /**
     * Before an item is dropped, deleted or given another channel or format: its posts that will still go out
     * go back to drafts or keep their schedule, as the person chose. No
     * choice while there are any refuses the change. Returns how many posts
     * couldn't go back to drafts (another member's request for approval, or
     * one already going out).
     */
    private function settleUpcomingPosts(PlanItem $item, ?string $choice, User $user): int
    {
        if (ItemPosts::upcoming($item)->isEmpty()) {
            return 0;
        }

        if ($choice === null) {
            throw ValidationException::withMessages(['posts' => __('socialhq_plan.errors.posts_choice')]);
        }

        return $choice === ItemPosts::TO_DRAFTS ? ItemPosts::toDrafts($item, $user) : 0;
    }

    /**
     * "Move to drafts" that left a post scheduled says so, once the change
     * is saved: that post can still go out.
     */
    private static function sayKeptPosts(int $kept): void
    {
        if ($kept > 0) {
            session()->flash('flash.warning', trans_choice('socialhq_plan.posts_choice.kept', $kept, ['count' => $kept]));
        }
    }

    private function ensureStatus(PlanItem $item, ItemStatus ...$allowed): void
    {
        if (! in_array($item->status, $allowed, true)) {
            throw ValidationException::withMessages(['item' => __('socialhq_plan.errors.item_changed')]);
        }
    }

    /**
     * A posting time keeps the zone it was written in: the one sent, else the
     * item's own, else the brand's main audience zone. No time, no zone.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withTimeZone(array $values, ?PlanItem $item, Workspace $workspace, User $user): array
    {
        if (! array_key_exists('suggested_time', $values)) {
            return Arr::except($values, 'suggested_timezone');
        }

        $time = data_get($values, 'suggested_time');

        return [
            ...$values,
            'suggested_timezone' => $time === null ? null : (data_get($values, 'suggested_timezone')
                ?? $item?->suggested_timezone
                ?? Audience::mainZone(PlanOptions::profile($workspace), $user->timezone)),
        ];
    }

    private function nextPosition(ContentPlan $plan, string $day): int
    {
        return (int) $plan->items()->whereDate('scheduled_on', $day)->max('position') + 1;
    }
}
