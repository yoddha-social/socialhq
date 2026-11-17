<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventRegion;
use App\SocialHq\Http\Requests\Plan\ConfirmShownEventsRequest;
use App\SocialHq\Http\Requests\Plan\VerifyCalendarEventRequest;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Plan\PlanMonth;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * "Confirm all shown": confirms, in one query, the unconfirmed dates of
     * the page's period, country and filter that the person may change (a
     * brand's approver confirms that brand's own dates only). Dates marked
     * "No longer listed" stay unconfirmed, as they do one at a time.
     */
    public function storeShown(ConfirmShownEventsRequest $request): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $today = EventController::today($request);
        $upcoming = $request->validated('view') === 'upcoming';
        $month = PlanMonth::tryParse($request->validated('month')) ?? $today->startOfMonth();
        [$from, $to] = EventController::period($today, $upcoming, $month);
        $country = EventController::country($request->validated('country'));
        $canManageShared = PlanAccess::canManageSharedEvents($request->user(), $workspace);

        // A plain update: no model events and no updated_at, like a single confirmation.
        $confirmed = CalendarEvent::query()
            ->visibleTo($workspace)
            ->forCountries($country === null ? null : [$country])
            ->overlapping($from->toDateString(), $to->toDateString())
            ->whereNull('verified_at')
            // A date its source no longer lists can't be confirmed (store() says why).
            ->whereNull('delisted_at')
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
