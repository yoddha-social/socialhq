<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\ReviewDecisionRequest;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefRounds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The review of a brief's creative: the current round is sent for review;
 * approvers approve it (the final creative) or ask for changes.
 */
class BriefReviewController extends Controller
{
    public function submit(Request $request, int $item): RedirectResponse
    {
        BriefRounds::submit($this->brief($request, $item), $request->user());

        return back();
    }

    public function approve(ReviewDecisionRequest $request, int $item): RedirectResponse
    {
        BriefRounds::approve($this->brief($request, $item, approve: true), $request->user(), $request->validated('note'));

        return back();
    }

    public function requestChanges(ReviewDecisionRequest $request, int $item): RedirectResponse
    {
        BriefRounds::requestChanges($this->brief($request, $item, approve: true), $request->user(), (string) $request->validated('note'));

        return back();
    }

    private function brief(Request $request, int $item, bool $approve = false): Brief
    {
        return PlanAccess::item($request, $item, $approve)->brief ?? abort(Response::HTTP_NOT_FOUND);
    }
}
