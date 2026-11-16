<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventRegion;
use App\SocialHq\Http\Requests\Plan\ConfirmShownEventsRequest;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * "Confirm all shown": confirms, in one query, the unconfirmed dates of
     * the page's period, country and filter that the person may change (a
     * brand's approver confirms that brand's own dates only).
     */
    public function storeShown(ConfirmShownEventsRequest $request): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $today = EventController::today($request);
        $upcoming = $request->validated('view') === 'upcoming';
        $month = EventController::month($request->validated('month'), $today->getTimezone()) ?? $today->startOfMonth();
        [$from, $to] = EventController::period($today, $upcoming, $month);
        $country = EventController::country($request->validated('country'));
        $canManageShared = PlanAccess::canManageSharedEvents($request->user(), $workspace);

        // A plain update: no model events and no updated_at, like a single confirmation.
        $confirmed = CalendarEvent::query()
            ->visibleTo($workspace)
            ->forCountries($country === null ? null : [$country])
            ->overlapping($from->toDateString(), $to->toDateString())
            ->whereNull('verified_at')
            ->when(! $canManageShared, fn (Builder $query) => $query->where('workspace_id', $workspace->id))
            ->when($request->validated('filter'), fn (Builder $query, string $filter) => match ($filter) {
                'nepal' => $query->where('region', EventRegion::Nepal),
                'international' => $query->where('region', EventRegion::International),
                'brand' => $query->whereNotNull('workspace_id'),
                default => $query,
            })
            ->toBase()
            ->update(['verified_at' => now(), 'verified_by' => $request->user()->id]);

        session()->flash('flash.banner', trans_choice('socialhq_events.confirm_shown.done', $confirmed, ['count' => $confirmed]));
        session()->flash('flash.bannerStyle', 'success');

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
