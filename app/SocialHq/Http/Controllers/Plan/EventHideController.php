<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\EventFlag;
use App\SocialHq\Events\EventHide;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A shared date this brand has no use for (owner decision 2026-11-24): the
 * brand's approvers hide it from this brand's events, plan and planner, and
 * show it again. Other brands keep it; a brand's own date is deleted instead.
 */
class EventHideController extends Controller
{
    public function store(Request $request, int $event): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $found = EventController::find($workspace, $event);

        if ($found->workspace_id !== null) {
            throw ValidationException::withMessages(['event' => __('socialhq_events.hide.own_date')]);
        }

        EventHide::query()->firstOrCreate(['workspace_id' => $workspace->id, 'event_id' => $found->id], ['hidden_by' => $request->user()->id]);
        EventFlag::resolveFor($found->id, $workspace->id, $request->user()->id);

        return back()->with('flash.success', __('socialhq_events.hide.done', ['name' => $found->name]));
    }

    public function destroy(Request $request, int $event): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $found = EventController::find($workspace, $event);

        EventHide::query()->where('workspace_id', $workspace->id)->where('event_id', $found->id)->delete();

        return back()->with('flash.success', __('socialhq_events.hide.undone', ['name' => $found->name]));
    }
}
