<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ItemPage;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use App\SocialHq\Plan\ReviewKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The production board: a workspace's briefs by stage, and moving them.
 * Only approvers move a card into or out of a decided column, or out of
 * In review.
 */
final class ProductionBoard
{
    /**
     * The columns that hold a review decision.
     */
    private const array DECIDED = [BriefStage::Approved, BriefStage::ChangesRequested];

    /**
     * The board's cards. Without a month, the work still open plus what was
     * approved for this month or later (approved work of past months stays
     * out, so the board doesn't load every brief ever made).
     *
     * @param  array{mine?: bool, kind?: ?string, month?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    public static function cards(Workspace $workspace, User $user, array $filters): array
    {
        $month = PlanMonth::tryParse($filters['month'] ?? null);
        $current = PlanMonth::current($user)->toDateString();

        return Brief::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['mine'] ?? false, fn ($query) => $query->where('assignee_id', $user->id))
            ->when(filled($filters['kind'] ?? null), fn ($query) => $query->where('kind', $filters['kind']))
            ->when(
                $month !== null,
                fn ($query) => $query->whereHas('item', fn ($items) => $items
                    ->whereBetween('scheduled_on', [$month->toDateString(), PlanMonth::end($month)->toDateString()])),
                fn ($query) => $query->where(fn ($open) => $open
                    ->where('stage', '!=', BriefStage::Approved)
                    ->orWhereHas('item', fn ($items) => $items->where('scheduled_on', '>=', $current))),
            )
            ->with(['item.socialAccount', 'assignee.avatarMedia', 'files'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Brief $brief): array => self::card($brief))
            ->values()
            ->all();
    }

    /**
     * The months that have briefs, newest first, as YYYY-MM.
     *
     * @return list<string>
     */
    public static function months(Workspace $workspace): array
    {
        return Brief::query()
            ->where('shq_briefs.workspace_id', $workspace->id)
            ->join('shq_plan_items', 'shq_plan_items.id', '=', 'shq_briefs.item_id')
            ->join('shq_content_plans', 'shq_content_plans.id', '=', 'shq_plan_items.plan_id')
            ->distinct()
            ->orderByDesc('shq_content_plans.month')
            ->pluck('shq_content_plans.month')
            ->map(fn (mixed $month): string => Carbon::parse($month)->format('Y-m'))
            ->values()
            ->all();
    }

    /**
     * Who may approve in the workspace, for the message shown to everyone else.
     *
     * @return list<string>
     */
    public static function approverNames(Workspace $workspace): array
    {
        return BriefTeam::members($workspace)
            ->filter(fn (User $member): bool => PlanAccess::canApprove($member, $workspace))
            ->map(fn (User $member): string => (string) $member->name)
            ->values()
            ->all();
    }

    /**
     * Put a card in a column at the given order. A move into Approved or
     * Changes requested is a review decision, made like the review endpoint
     * makes it (BriefRounds::decide); with $shownRound, only on the round
     * the approver saw.
     *
     * @param  list<int>  $orderedIds
     */
    public static function move(Brief $brief, User $user, BriefStage $stage, array $orderedIds, ?int $shownRound = null): void
    {
        DB::transaction(function () use ($brief, $user, $stage, $orderedIds, $shownRound): void {
            $locked = Brief::query()->lockForUpdate()->findOrFail($brief->id);
            $from = $locked->stage;
            $round = $locked->currentRound() ?: null;

            if ($from !== $stage) {
                self::assertMayMove($locked, $user, $from, $stage);
            }

            if ($from !== $stage && in_array($stage, self::DECIDED, true)) {
                BriefRounds::decide($locked, $user, $stage, shownRound: $shownRound);
            } else {
                BriefRounds::moveTo($locked, $stage, $user, 0);

                if ($from !== $stage) {
                    match ($stage) {
                        BriefStage::InReview => BriefRounds::note($locked, $user, ReviewKind::Submitted, $round),
                        default => $from === BriefStage::Approved ? BriefRounds::note($locked, $user, ReviewKind::Reopened, $round) : null,
                    };
                }
            }

            $column = Brief::query()
                ->where('workspace_id', $locked->workspace_id)
                ->where('stage', $stage)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id');

            // The client's order first, with the moved card in it; cards it didn't send keep their order after.
            $order = collect($orderedIds)->filter(fn (int $id): bool => $column->contains($id))->values();

            if (! $order->contains($locked->id)) {
                $order->push($locked->id);
            }

            $order = $order->merge($column->diff($order))->values();

            Brief::reorder($order->all());
        });
    }

    /**
     * The review steps the board can't skip: only approvers decide (move a
     * card into or out of Approved or Changes requested) or take a card out
     * of review, and a card goes to In review only with a round that may be
     * reviewed (it has files and hasn't been decided yet). A decision itself
     * is checked by BriefRounds::decide.
     */
    private static function assertMayMove(Brief $brief, User $user, BriefStage $from, BriefStage $to): void
    {
        $approversOnly = in_array($from, self::DECIDED, true) || in_array($to, self::DECIDED, true) || $from === BriefStage::InReview;

        if ($approversOnly && ! PlanAccess::canApprove($user, $brief->workspace)) {
            throw new HttpException(Response::HTTP_FORBIDDEN, __('socialhq_production.board.errors.approvers_only', [
                'names' => implode(', ', self::approverNames($brief->workspace)),
            ]));
        }

        if ($to === BriefStage::InReview && BriefRounds::submitBlocker($brief) !== null) {
            throw new HttpException(Response::HTTP_CONFLICT, __('socialhq_production.board.errors.review_needs_files'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function card(Brief $brief): array
    {
        $item = $brief->item;
        $round = (int) $brief->files->max('round');
        $thumbnail = $brief->files
            ->sortByDesc('id')
            ->first(fn (BriefFile $file): bool => MediaType::fromMime($file->mime_type) === MediaType::Image);

        return [
            'id' => $brief->id,
            'item_id' => $item->id,
            'title' => $item->title,
            'kind' => $brief->kind->value,
            'stage' => $brief->stage->value,
            'position' => $brief->position,
            'platform' => $item->socialAccount?->platform->value ?? $item->platform,
            'channel' => $item->socialAccount?->display_name ?: ($item->socialAccount?->username ?? Platform::tryFrom((string) $item->platform)?->label()),
            'format' => $item->content_type?->label(),
            'scheduled_on' => $item->scheduled_on->toDateString(),
            'due_on' => $brief->due_on?->toDateString(),
            'assignee' => ItemPage::person($brief->assignee),
            'thumbnail_url' => $thumbnail === null ? null : route('socialhq.plan.items.brief.files.show', ['item' => $item->id, 'file' => $thumbnail->id]),
            'rounds' => $round,
        ];
    }
}
