<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Account;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Writes one imported row (official list or Nager.Date) the same way: the
 * row of the source is found by import_key + import_date, wherever the team
 * has since moved it. A new row is created unconfirmed; a row that changed
 * at the source is updated without touching its timestamps, so a later
 * import can still tell it apart from a row a person edited. Rows a person
 * added, edited, narrowed to one brand or deleted are never overwritten or
 * brought back, and an unchanged row keeps its confirmation.
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

        $existing->fill($row);

        if (! $existing->isDirty()) {
            return 'skipped';
        }

        $existing->timestamps = false;
        $existing->save();

        return 'updated';
    }

    public static function editedByPerson(CalendarEvent $event): bool
    {
        return $event->created_by !== null
            || ($event->updated_at !== null && $event->created_at !== null && $event->updated_at->greaterThan($event->created_at));
    }
}
