<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\Plan\MovePlanItemRequest;
use App\SocialHq\Http\Requests\Plan\StorePlanItemRequest;
use App\SocialHq\Http\Requests\Plan\UpdatePlanItemRequest;
use App\SocialHq\Knowledge\Audience\Audience;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanAccess;
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

        $this->change($found, function () use ($found, $request, $channel): void {
            $found->update([
                ...self::withTimeZone($request->validated(), $found, $found->plan->workspace, $request->user()),
                'platform' => $channel->platform->value,
            ]);

            // Copy approved (or in review) for one channel and format isn't approved for another.
            if ($found->wasChanged(['social_account_id', 'content_type'])) {
                ItemCopy::reopen($found, $request->user(), __('socialhq_production.review.reopened_format'));
            }

            $found->acceptForWork();
        });

        return back();
    }

    public function move(MovePlanItemRequest $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);

        if ($found->scheduled_on->toDateString() !== $request->validated('scheduled_on')) {
            $this->change($found, function () use ($found, $request): void {
                $found->update([
                    'scheduled_on' => $request->validated('scheduled_on'),
                    'position' => $this->nextPosition($found->plan, $request->validated('scheduled_on')),
                ]);
                $found->acceptForWork();
            });
        }

        return back();
    }

    /**
     * An AI suggestion becomes part of the plan.
     */
    public function accept(Request $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $this->ensureStatus($found, ItemStatus::Suggested);

        $this->change($found, fn () => $found->update(['status' => ItemStatus::Planned]));

        return back();
    }

    public function drop(Request $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $this->ensureStatus($found, ItemStatus::Suggested, ItemStatus::Planned);

        $this->change($found, fn () => $found->update(['status' => ItemStatus::Dropped]));

        return back();
    }

    /**
     * A dropped AI suggestion nobody started work on comes back as an open
     * suggestion, to accept or drop again; anything else comes back planned.
     */
    public function restore(Request $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $this->ensureStatus($found, ItemStatus::Dropped);

        $untouchedSuggestion = $found->source === ItemSource::Ai
            && ! $found->versions()->exists()
            && ! $found->brief()->exists();

        $this->change($found, fn () => $found->update(['status' => $untouchedSuggestion ? ItemStatus::Suggested : ItemStatus::Planned]));

        return back();
    }

    /**
     * Approved copy, an approved creative or a created post is approved work:
     * only approvers delete an item that has one. Others drop it instead.
     */
    public function destroy(Request $request, int $item): RedirectResponse
    {
        $found = PlanAccess::item($request, $item);
        $found->loadMissing('brief');

        $approvedWork = $found->copy_status === CopyStatus::Approved
            || $found->brief?->stage === BriefStage::Approved
            || $found->post_created_at !== null;

        if ($approvedWork && ! PlanAccess::canApprove($request->user(), $found->plan->workspace)) {
            throw ValidationException::withMessages(['item' => __('socialhq_plan.errors.delete_approved')]);
        }

        $this->change($found, fn () => $found->delete());

        return back();
    }

    /**
     * Every open AI suggestion of the month becomes part of the plan.
     */
    public function acceptAll(Request $request, string $month): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);
        $plan = PlanMonth::find($workspace, PlanMonth::parse($month, $request->user()));

        if ($plan !== null) {
            DB::transaction(function () use ($plan): void {
                $accepted = $plan->items()->where('status', ItemStatus::Suggested)->update(['status' => ItemStatus::Planned, 'updated_at' => now()]);

                if ($accepted > 0) {
                    $plan->reopen();
                }
            });
        }

        return back();
    }

    private function change(PlanItem $item, callable $write): void
    {
        DB::transaction(function () use ($item, $write): void {
            $write();
            $item->plan->reopen();
        });
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
