<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\UpdateBriefRequest;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefStage;
use App\SocialHq\Production\FormatNotes;
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
    public function store(Request $request, int $item): RedirectResponse
    {
        $planItem = PlanAccess::item($request, $item);
        $kind = $planItem->creative->briefKind();

        if ($kind === null) {
            throw ValidationException::withMessages(['brief' => __('socialhq_production.brief.errors.not_needed')]);
        }

        DB::transaction(function () use ($planItem, $kind, $request): void {
            if (Brief::query()->where('item_id', $planItem->id)->lockForUpdate()->exists()) {
                return;
            }

            Brief::query()->create([
                'item_id' => $planItem->id,
                'workspace_id' => $planItem->workspace_id,
                'kind' => $kind,
                'stage' => BriefStage::Todo,
                'format_notes' => FormatNotes::for($planItem),
                'position' => (int) Brief::query()->where('workspace_id', $planItem->workspace_id)->where('stage', BriefStage::Todo)->max('position') + 1,
                'created_by' => $request->user()->id,
            ]);
        });

        return back();
    }

    public function update(UpdateBriefRequest $request, int $item): RedirectResponse
    {
        $brief = PlanAccess::item($request, $item)->brief ?? abort(Response::HTTP_NOT_FOUND);

        $brief->update($request->validated());

        return back();
    }
}
