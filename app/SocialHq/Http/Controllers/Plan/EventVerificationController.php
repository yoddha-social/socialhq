<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Http\Requests\Plan\VerifyCalendarEventRequest;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person confirms an event's date (or takes the confirmation back). Only
 * confirmed dates reach the AI planner.
 *
 * Confirming leaves updated_at alone: it is not an edit, so a later import of
 * the official dates may still refresh the row. It confirms only the name
 * and dates the approver saw.
 */
class EventVerificationController extends Controller
{
    public function store(VerifyCalendarEventRequest $request, int $event): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $found = EventController::find($workspace, $event);
        EventController::ensureMayChange($request, $workspace, shared: $found->workspace_id === null);

        DB::transaction(function () use ($request, $found): void {
            $locked = CalendarEvent::query()->lockForUpdate()->findOrFail($found->id);

            $shown = $locked->name === $request->validated('name')
                && $locked->starts_on->toDateString() === $request->validated('starts_on')
                && $locked->ends_on->toDateString() === $request->validated('ends_on');

            if (! $shown) {
                throw ValidationException::withMessages(['event' => __('socialhq_events.errors.changed')]);
            }

            if ($locked->isDelisted()) {
                throw ValidationException::withMessages(['event' => __('socialhq_events.errors.delisted')]);
            }

            if (! $locked->isVerified()) {
                self::saveQuietly($locked, ['verified_at' => now(), 'verified_by' => $request->user()->id]);
            }
        });

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
