<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventCategory;
use App\SocialHq\Events\EventRegion;
use App\SocialHq\Events\EventsForMonth;
use App\SocialHq\Events\ImportOfficialEvents;
use App\SocialHq\Http\Requests\Plan\StoreCalendarEventRequest;
use App\SocialHq\Http\Requests\Plan\UpdateCalendarEventRequest;
use App\SocialHq\Plan\PlanAccess;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The events page: dated festivals, holidays and days, confirmed by a person.
 * Every member reads them; people who approve posts add, change and delete them.
 */
class EventController extends Controller
{
    public const UPCOMING_DAYS = 90;

    public function index(Request $request): Response
    {
        $workspace = PlanAccess::workspace($request);
        $today = CarbonImmutable::now(Timezone::normalize($request->user()->timezone))->startOfDay();
        $upcoming = $request->query('view') === 'upcoming';
        $month = self::month($request->query('month'), $today->getTimezone()) ?? $today->startOfMonth();
        [$from, $to] = $upcoming ? [$today, $today->addDays(self::UPCOMING_DAYS - 1)] : [$month, $month->endOfMonth()];

        return Inertia::render('socialhq/plan/Events', [
            'view' => $upcoming ? 'upcoming' : 'month',
            'month' => $month->format('Y-m'),
            'today' => $today->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => EventsForMonth::forWorkspace($workspace, $from, $to)->map(fn (CalendarEvent $event): array => self::row($event))->values(),
            'hasEvents' => CalendarEvent::query()->visibleTo($workspace)->exists(),
            'categories' => array_map(fn (EventCategory $category): string => $category->value, EventCategory::cases()),
            'abilities' => PlanAccess::abilities($request->user(), $workspace),
        ]);
    }

    public function store(StoreCalendarEventRequest $request): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $brandOnly = $request->boolean('brand_only');
        $key = ImportOfficialEvents::keyFor($request->validated('name'));

        self::whileLocked($workspace, function () use ($request, $workspace, $brandOnly, $key): void {
            self::ensureUnique($workspace, $brandOnly, $key, $request->validated('starts_on'));

            CalendarEvent::query()->create([
                ...self::attributes($request->validated()),
                'account_id' => $workspace->account_id,
                'workspace_id' => $brandOnly ? $workspace->id : null,
                'key' => $key,
                'region' => self::region($brandOnly, EventCategory::from($request->validated('category')), null),
                'created_by' => $request->user()->id,
            ]);
        });

        return back();
    }

    public function update(UpdateCalendarEventRequest $request, int $event): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        $found = self::find($workspace, $event);
        $brandOnly = $request->boolean('brand_only');

        self::whileLocked($workspace, function () use ($request, $workspace, $found, $brandOnly): void {
            self::ensureUnique($workspace, $brandOnly, $found->key, $request->validated('starts_on'), $found->id);

            $found->update([
                ...self::attributes($request->validated()),
                'workspace_id' => $brandOnly ? $workspace->id : null,
                'region' => self::region($brandOnly, EventCategory::from($request->validated('category')), $found->region),
            ]);
        });

        return back();
    }

    public function destroy(Request $request, int $event): RedirectResponse
    {
        self::find(PlanAccess::workspace($request, approve: true), $event)->delete();

        return back();
    }

    /**
     * An event the workspace sees: the account's shared dates and its own.
     * Another account's or another brand's event is not found.
     */
    public static function find(Workspace $workspace, int $id): CalendarEvent
    {
        return CalendarEvent::query()->visibleTo($workspace)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'name_ne' => $event->name_ne,
            'category' => $event->category->value,
            'region' => $event->region->value,
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'bs_date' => $event->bs_date,
            'public_holiday' => $event->public_holiday,
            'description' => $event->description,
            'source_url' => $event->source_url,
            'source_name' => $event->source_name,
            'brand_only' => $event->workspace_id !== null,
            'verified' => $event->isVerified(),
            'verified_by' => $event->verifier?->name,
            'verified_at' => $event->verified_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function attributes(array $validated): array
    {
        return [
            'name' => data_get($validated, 'name'),
            'name_ne' => data_get($validated, 'name_ne'),
            'category' => data_get($validated, 'category'),
            'starts_on' => data_get($validated, 'starts_on'),
            'ends_on' => data_get($validated, 'ends_on'),
            'bs_date' => data_get($validated, 'bs_date'),
            'public_holiday' => (bool) data_get($validated, 'public_holiday'),
            'description' => data_get($validated, 'description'),
            'source_url' => data_get($validated, 'source_url'),
        ];
    }

    /**
     * A brand-only date belongs to the brand; a shared one keeps its region, or
     * takes the one its category suggests.
     */
    private static function region(bool $brandOnly, EventCategory $category, ?EventRegion $current): EventRegion
    {
        if ($brandOnly) {
            return EventRegion::Brand;
        }

        if ($current !== null && $current !== EventRegion::Brand) {
            return $current;
        }

        // A shared date is never of the brand kind (the requests refuse it).
        return in_array($category, [EventCategory::InternationalDay, EventCategory::Commercial], true)
            ? EventRegion::International
            : EventRegion::Nepal;
    }

    /**
     * Runs a duplicate check and its write with the account row locked, so two
     * people adding the same date at once can't both pass the check (no unique
     * index covers account-wide dates, whose workspace_id is null).
     */
    private static function whileLocked(Workspace $workspace, Closure $write): void
    {
        DB::transaction(function () use ($workspace, $write): void {
            Account::query()->whereKey($workspace->account_id)->lockForUpdate()->first();

            $write();
        });
    }

    private static function ensureUnique(Workspace $workspace, bool $brandOnly, string $key, string $startsOn, ?int $except = null): void
    {
        $taken = CalendarEvent::query()
            ->where('account_id', $workspace->account_id)
            ->when($brandOnly, fn ($query) => $query->where('workspace_id', $workspace->id), fn ($query) => $query->whereNull('workspace_id'))
            ->where('key', $key)
            ->where('starts_on', $startsOn)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('socialhq_events.errors.duplicate')]);
        }
    }

    private static function month(mixed $value, DateTimeZone $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $parts) !== 1 || (int) $parts[1] < 2000 || (int) $parts[1] > 2100) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $value, $timezone);
    }
}
