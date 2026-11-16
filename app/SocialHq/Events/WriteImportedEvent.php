<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Account;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Writes one imported row (official list or Nager.Date) the same way: the
 * row of the source is found by import_key + import_date, wherever the team
 * has since moved it. A new row is created unconfirmed; a row that changed
 * at the source is updated without touching its timestamps, so a later
 * import can still tell it apart from a row a person edited. Rows a person
 * added, edited, narrowed to one brand or deleted are never overwritten or
 * brought back, and an unchanged row keeps its confirmation.
 *
 * delist() handles the other side: imported rows the source no longer lists
 * lose their confirmation and are flagged, so planning stops using a date
 * the source corrected; a row listed again is no longer flagged.
 */
final class WriteImportedEvent
{
    /**
     * @param  array<string, mixed>  $row  Attributes of the event, with `key` and `starts_on`.
     * @param  Closure(Builder<CalendarEvent>): mixed  $source  Narrows the lookup to this importer's rows.
     * @return 'created'|'updated'|'skipped'
     */
    public static function handle(Account $account, array $row, Closure $source): string
    {
        $query = CalendarEvent::query()
            ->withTrashed()
            ->where('account_id', $account->id)
            ->where('import_key', data_get($row, 'key'))
            ->where('import_date', data_get($row, 'starts_on'));
        $source($query);
        $existing = $query->first();

        if ($existing === null) {
            CalendarEvent::query()->create([
                ...$row,
                'account_id' => $account->id,
                'workspace_id' => null,
                'import_key' => data_get($row, 'key'),
                'import_date' => data_get($row, 'starts_on'),
            ]);

            return 'created';
        }

        if ($existing->trashed() || self::editedByPerson($existing)) {
            return 'skipped';
        }

        $existing->fill([...$row, 'delisted_at' => null]);

        if (! $existing->isDirty()) {
            return 'skipped';
        }

        $existing->timestamps = false;
        $existing->save();

        return 'updated';
    }

    /**
     * Flag the imported rows of $candidates that the source no longer lists
     * ($listed: their identities as $identity builds them), and take their
     * confirmation away. Rows a person edited are the team's and stay as
     * they are, like the import never overwrites them.
     *
     * @param  Builder<CalendarEvent>  $candidates
     * @param  Collection<string, mixed>  $listed  keyed by identity
     * @param  Closure(CalendarEvent): string  $identity
     * @return int how many rows are flagged (now or before)
     */
    public static function delist(Builder $candidates, Collection $listed, Closure $identity): int
    {
        $gone = $candidates
            ->whereNotNull('import_key')
            ->get()
            ->reject(fn (CalendarEvent $event): bool => $listed->has($identity($event)) || self::editedByPerson($event));

        $gone->reject(fn (CalendarEvent $event): bool => $event->isDelisted())
            ->each(function (CalendarEvent $event): void {
                $event->forceFill(['delisted_at' => now(), 'verified_at' => null, 'verified_by' => null]);
                $event->timestamps = false;
                $event->save();
            });

        return $gone->count();
    }

    public static function editedByPerson(CalendarEvent $event): bool
    {
        return $event->created_by !== null
            || ($event->updated_at !== null && $event->created_at !== null && $event->updated_at->greaterThan($event->created_at));
    }
}
