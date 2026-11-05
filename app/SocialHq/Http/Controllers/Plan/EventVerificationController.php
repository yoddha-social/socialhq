<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A person confirms an event's date (or takes the confirmation back). Only
 * confirmed dates reach the AI planner.
 *
 * Confirming leaves updated_at alone: it is not an edit, so a later import of
 * the official dates may still refresh the row.
 */
class EventVerificationController extends Controller
{
    public function store(Request $request, int $event): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $found = EventController::find($workspace, $event);
        EventController::ensureMayChange($request, $workspace, shared: $found->workspace_id === null);

        if (! $found->isVerified()) {
            self::saveQuietly($found, ['verified_at' => now(), 'verified_by' => $request->user()->id]);
        }

        return back();
    }

    public function destroy(Request $request, int $event): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $found = EventController::find($workspace, $event);
        EventController::ensureMayChange($request, $workspace, shared: $found->workspace_id === null);

        self::saveQuietly($found, ['verified_at' => null, 'verified_by' => null]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function saveQuietly(CalendarEvent $event, array $attributes): void
    {
        $event->forceFill($attributes);
        $event->timestamps = false;
        $event->save();
    }
}
