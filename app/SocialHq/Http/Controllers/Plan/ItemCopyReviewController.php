<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\ReviewDecisionRequest;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The review of an item's copy: anyone working on the plan sends it for
 * review; approvers approve it or ask for changes.
 */
class ItemCopyReviewController extends Controller
{
    public function submit(Request $request, int $item): RedirectResponse
    {
        ItemCopy::submit(PlanAccess::item($request, $item), $request->user());

        return back();
    }

    public function approve(ReviewDecisionRequest $request, int $item): RedirectResponse
    {
        ItemCopy::approve(PlanAccess::item($request, $item, approve: true), $request->user(), $request->validated('note'));

        return back();
    }

    public function requestChanges(ReviewDecisionRequest $request, int $item): RedirectResponse
    {
        ItemCopy::requestChanges(PlanAccess::item($request, $item, approve: true), $request->user(), (string) $request->validated('note'));

        return back();
    }
}
