<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\EventFlag;
use App\SocialHq\Http\Requests\Plan\StoreEventFlagRequest;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Members can't change dates, but the people planning often know them best
 * (owner decision 2026-11-24): anyone working on the plan flags a date with
 * a note, and the brand's approvers see it on the date and in "Waiting for
 * you". Confirming, changing or hiding the date handles it; an approver can
 * also mark a note handled.
 */
class EventFlagController extends Controller
{
    public function store(StoreEventFlagRequest $request, int $event): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request);
        $found = EventController::find($workspace, $event);

        EventFlag::query()->create([
            'workspace_id' => $workspace->id,
            'event_id' => $found->id,
            'user_id' => $request->user()->id,
            'note' => $request->validated('note'),
        ]);

        return back()->with('flash.success', __('socialhq_events.flag.sent'));
    }

    public function destroy(Request $request, int $flag): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $found = EventFlag::query()->where('workspace_id', $workspace->id)->open()->findOrFail($flag);

        $found->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id]);

        return back()->with('flash.success', __('socialhq_events.flag.resolved'));
    }
}
