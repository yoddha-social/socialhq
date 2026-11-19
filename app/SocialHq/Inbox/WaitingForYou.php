<?php

declare(strict_types=1);

namespace App\SocialHq\Inbox;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanStatus;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * What waits for this person in the current workspace (QA 2026-11-18 P1-1:
 * nobody was told). An approver gets every review: monthly plans, copy,
 * creatives and posts. Anyone working on the plan gets the copy and creatives
 * sent back to them with changes requested: copy of an item they own or
 * whose latest version they wrote, a creative assigned to them (or, with
 * nobody assigned, on an item they own). Approving plans, copy and creatives
 * follows `approvePosts`, the same gate as approving posts, so one check
 * decides all four.
 *
 * One list for the inbox page, a count for the sidebar, and the months with
 * something waiting for the plan's month picker.
 */
final class WaitingForYou
{
    /** Each section shows at most this many; the count is the full number. */
    public const int LIMIT = 50;

    /**
     * @return array{plans: list<array<string, mixed>>, copy: list<array<string, mixed>>, creatives: list<array<string, mixed>>, posts: list<array<string, mixed>>, copy_changes: list<array<string, mixed>>, creative_changes: list<array<string, mixed>>}
     */
    public static function sections(User $user, Workspace $workspace): array
    {
        $approver = PlanAccess::canApprove($user, $workspace);
        $worker = PlanAccess::canWork($user, $workspace);

        return [
            'plans' => $approver ? self::plans($workspace)->with('submitter')->orderBy('month')->limit(self::LIMIT)->get()
                ->map(fn (ContentPlan $plan): array => [
                    'id' => $plan->id,
                    'month' => $plan->month->format('Y-m'),
                    'by' => $plan->submitter?->name,
                    'at' => $plan->submitted_at?->toIso8601String(),
                ])->values()->all() : [],
            'copy' => $approver ? self::itemRows(self::copyInReview($workspace)) : [],
            'creatives' => $approver ? self::briefRows(self::briefs($workspace, BriefStage::InReview)) : [],
            'posts' => $approver ? self::pendingPosts($workspace)->with('socialAccount')->orderBy('scheduled_at')->limit(self::LIMIT)->get()
                ->map(fn (Post $post): array => [
                    'id' => $post->id,
                    'excerpt' => Str::limit(Str::squish((string) $post->content), 120),
                    'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                    'channel' => $post->socialAccount?->accountDisplayName(),
                    'platform' => $post->socialAccount?->platform?->value,
                ])->values()->all() : [],
            'copy_changes' => $worker ? self::itemRows(self::copyChangesFor($workspace, $user)) : [],
            'creative_changes' => $worker ? self::briefRows(self::creativeChangesFor($workspace, $user)) : [],
        ];
    }

    public static function count(User $user, Workspace $workspace): int
    {
        $count = 0;

        if (PlanAccess::canApprove($user, $workspace)) {
            $count += self::plans($workspace)->count()
                + self::copyInReview($workspace)->count()
                + self::briefs($workspace, BriefStage::InReview)->count()
                + self::pendingPosts($workspace)->count();
        }

        if (PlanAccess::canWork($user, $workspace)) {
            $count += self::copyChangesFor($workspace, $user)->count()
                + self::creativeChangesFor($workspace, $user)->count();
        }

        return $count;
    }

    /**
     * The plan months ("YYYY-MM") with a plan, copy or creative waiting for
     * this person.
     *
     * @return list<string>
     */
    public static function months(User $user, Workspace $workspace): array
    {
        $months = collect();
        // The distinct plan ids only, then their months: never a row per item.
        $planIds = fn (Builder $query, string $column): array => $query->distinct()->pluck($column)->all();
        $itemMonths = fn (Builder $items): array => self::monthsOfPlans($planIds($items, 'plan_id'));
        $briefMonths = fn (Builder $briefs): array => self::monthsOfPlans($planIds(
            PlanItem::query()->whereIn('id', $briefs->select('item_id')),
            'plan_id',
        ));

        if (PlanAccess::canApprove($user, $workspace)) {
            $months->push(...self::plans($workspace)->pluck('month')->map(fn ($month): string => $month->format('Y-m'))->all());
            $months->push(...$itemMonths(self::copyInReview($workspace)));
            $months->push(...$briefMonths(self::briefs($workspace, BriefStage::InReview)));
        }

        if (PlanAccess::canWork($user, $workspace)) {
            $months->push(...$itemMonths(self::copyChangesFor($workspace, $user)));
            $months->push(...$briefMonths(self::creativeChangesFor($workspace, $user)));
        }

        return $months->filter()->unique()->sort()->values()->all();
    }

    /**
     * @param  list<int|string>  $planIds
     * @return list<string>
     */
    private static function monthsOfPlans(array $planIds): array
    {
        return $planIds === [] ? [] : ContentPlan::query()->whereIn('id', $planIds)->pluck('month')
            ->map(fn ($month): string => $month->format('Y-m'))->all();
    }

    /**
     * @return Builder<ContentPlan>
     */
    private static function plans(Workspace $workspace): Builder
    {
        return ContentPlan::query()->where('workspace_id', $workspace->id)->where('status', PlanStatus::InReview);
    }

    /**
     * @return Builder<PlanItem>
     */
    private static function items(Workspace $workspace): Builder
    {
        return PlanItem::query()->where('workspace_id', $workspace->id)->where('status', '!=', ItemStatus::Dropped);
    }

    /**
     * @return Builder<PlanItem>
     */
    private static function copyInReview(Workspace $workspace): Builder
    {
        return self::items($workspace)->where('copy_status', CopyStatus::InReview);
    }

    /**
     * Copy sent back with changes requested, on an item the person owns or
     * whose latest version they wrote.
     *
     * @return Builder<PlanItem>
     */
    private static function copyChangesFor(Workspace $workspace, User $user): Builder
    {
        return self::items($workspace)
            ->where('copy_status', CopyStatus::ChangesRequested)
            ->where(fn (Builder $query) => $query
                ->where('owner_id', $user->id)
                ->orWhereHas('latestVersion', fn (Builder $version) => $version->where('created_by', $user->id)));
    }

    /**
     * Creatives sent back with changes: assigned to the person, or with
     * nobody assigned on an item they own.
     *
     * @return Builder<Brief>
     */
    private static function creativeChangesFor(Workspace $workspace, User $user): Builder
    {
        return self::briefs($workspace, BriefStage::ChangesRequested)
            ->where(fn (Builder $query) => $query
                ->where('assignee_id', $user->id)
                ->orWhere(fn (Builder $unassigned) => $unassigned
                    ->whereNull('assignee_id')
                    ->whereHas('item', fn (Builder $item) => $item->where('owner_id', $user->id))));
    }

    /**
     * @return Builder<Brief>
     */
    private static function briefs(Workspace $workspace, BriefStage $stage): Builder
    {
        return Brief::query()
            ->where('workspace_id', $workspace->id)
            ->where('stage', $stage)
            ->whereHas('item', fn (Builder $item) => $item->where('status', '!=', ItemStatus::Dropped));
    }

    /**
     * @return Builder<Post>
     */
    private static function pendingPosts(Workspace $workspace): Builder
    {
        return Post::query()->where('workspace_id', $workspace->id)->where('status', PostStatus::PendingApproval);
    }

    /**
     * @param  Builder<PlanItem>  $items
     * @return list<array<string, mixed>>
     */
    private static function itemRows(Builder $items): array
    {
        return $items->with('plan:id,month')->orderBy('scheduled_on')->orderBy('id')->limit(self::LIMIT)->get()
            ->map(fn (PlanItem $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'scheduled_on' => $item->scheduled_on->toDateString(),
                'month' => $item->plan?->month->format('Y-m'),
                'platform' => $item->platform,
            ])->values()->all();
    }

    /**
     * @param  Builder<Brief>  $briefs
     * @return list<array<string, mixed>>
     */
    private static function briefRows(Builder $briefs): array
    {
        return $briefs->with(['item:id,title,scheduled_on', 'assignee:id,name'])->addSelect(['latest_round' => BriefFile::query()->selectRaw('max(round)')->whereColumn('brief_id', 'shq_briefs.id')])->orderBy('updated_at')->orderBy('id')->limit(self::LIMIT)->get()
            ->map(fn (Brief $brief): array => [
                'id' => $brief->id,
                'item_id' => $brief->item_id,
                'title' => $brief->item?->title,
                'kind' => $brief->kind->value,
                'round' => (int) $brief->latest_round,
                'scheduled_on' => $brief->item?->scheduled_on->toDateString(),
                'assignee' => $brief->assignee?->name,
            ])->values()->all();
    }
}
