<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ItemPage;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\ReviewKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The production board: a workspace's briefs by stage, and moving them.
 * Only approvers move a card into Approved or out of it.
 */
final class ProductionBoard
{
    /**
     * @param  array{mine?: bool, kind?: ?string, month?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    public static function cards(Workspace $workspace, User $user, array $filters): array
    {
        $month = filled($filters['month'] ?? null) ? Carbon::createFromFormat('!Y-m', (string) $filters['month']) : null;

        return Brief::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['mine'] ?? false, fn ($query) => $query->where('assignee_id', $user->id))
            ->when(filled($filters['kind'] ?? null), fn ($query) => $query->where('kind', $filters['kind']))
            ->when($month !== null, fn ($query) => $query->whereHas('item', fn ($items) => $items
                ->whereBetween('scheduled_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])))
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
            ->pluck('shq_plan_items.scheduled_on')
            ->map(fn (mixed $day): string => Carbon::parse($day)->format('Y-m'))
            ->unique()
            ->sortDesc()
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
     * Put a card in a column at the given order.
     *
     * @param  list<int>  $orderedIds
     */
    public static function move(Brief $brief, User $user, BriefStage $stage, array $orderedIds): void
    {
        $touchesApproved = $brief->stage !== $stage && ($stage === BriefStage::Approved || $brief->stage === BriefStage::Approved);

        if ($touchesApproved && ! PlanAccess::canApprove($user, $brief->workspace)) {
            throw new HttpException(Response::HTTP_FORBIDDEN, __('socialhq_production.board.errors.approvers_only', [
                'names' => implode(', ', self::approverNames($brief->workspace)),
            ]));
        }

        DB::transaction(function () use ($brief, $user, $stage, $orderedIds): void {
            $locked = Brief::query()->lockForUpdate()->findOrFail($brief->id);
            $from = $locked->stage;

            BriefRounds::moveTo($locked, $stage, $user, 0);

            if ($from !== $stage && $stage === BriefStage::Approved) {
                BriefRounds::note($locked, $user, ReviewKind::Approved, $locked->currentRound() ?: null);
            } elseif ($from === BriefStage::Approved && $stage !== BriefStage::Approved) {
                BriefRounds::note($locked, $user, ReviewKind::Reopened, $locked->currentRound() ?: null);
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

            $order->each(fn (int $id, int $position) => Brief::query()->whereKey($id)->update(['position' => $position]));
        });
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
