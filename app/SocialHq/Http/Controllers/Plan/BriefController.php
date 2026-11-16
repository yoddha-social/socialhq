<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\UpdateBriefRequest;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefRounds;
use App\SocialHq\Production\BriefStage;
use App\SocialHq\Production\FormatNotes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The creative brief of a plan item: one per item, made when its creative
 * needs a graphic or a video.
 */
class BriefController extends Controller
{
    /**
     * The fields that say what the creative must show.
     */
    private const array CONTENT = ['format_notes', 'on_screen_text', 'direction', 'references'];

    public function store(Request $request, int $item): RedirectResponse
    {
        $planItem = PlanAccess::item($request, $item);
        $kind = $planItem->creative->briefKind();

        if ($kind === null) {
            throw ValidationException::withMessages(['brief' => __('socialhq_production.brief.errors.not_needed')]);
        }

        // Under the item's lock, like saving copy: an AI draft finishing at the same moment
        // waits, then sees the item accepted and keeps it with its new brief.
        DB::transaction(function () use ($planItem, $kind, $request): void {
            $locked = PlanItem::query()->lockForUpdate()->findOrFail($planItem->id);

            try {
                Brief::query()->firstOrCreate(['item_id' => $locked->id], [
                    'workspace_id' => $locked->workspace_id,
                    'kind' => $kind,
                    'stage' => BriefStage::Todo,
                    'format_notes' => FormatNotes::for($locked),
                    'position' => (int) Brief::query()->where('workspace_id', $locked->workspace_id)->where('stage', BriefStage::Todo)->max('position') + 1,
                    'created_by' => $request->user()->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A second click raced the first: the brief exists, which is what was asked for.
            }

            $locked->acceptForWork();
        });

        return back();
    }

    /**
     * Changing what an approved creative must show (its format, text,
     * direction or references) reopens it, so the next upload is a new
     * round that gets reviewed.
     */
    public function update(UpdateBriefRequest $request, int $item): RedirectResponse
    {
        $brief = PlanAccess::item($request, $item)->brief ?? abort(Response::HTTP_NOT_FOUND);

        DB::transaction(function () use ($brief, $request): void {
            $locked = Brief::query()->lockForUpdate()->findOrFail($brief->id);
            $locked->fill($request->validated());
            $reopen = $locked->stage === BriefStage::Approved && $locked->isDirty(self::CONTENT);
            $locked->save();

            if ($reopen) {
                BriefRounds::moveTo($locked, BriefStage::InProgress);
                BriefRounds::note($locked, $request->user(), ReviewKind::Reopened, $locked->currentRound() ?: null, __('socialhq_production.review.reopened_brief'));
            }
        });

        return back();
    }
}
