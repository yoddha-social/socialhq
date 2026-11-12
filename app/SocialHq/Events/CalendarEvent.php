<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One dated occurrence of a festival, holiday or day the brands may post
 * about. Dates come from official sources and a person confirms them in the
 * app; AI never calculates them. Changing the date or the name clears the
 * confirmation.
 *
 * import_key and import_date record the row of the official list an event
 * came from (null for dates people added); the app never changes them.
 * Deleting only hides an event, so a re-import doesn't bring it back.
 *
 * country_code (ISO 3166-1 alpha-2) is the country that observes it: NP for
 * Nepal's dates, another code for a country's public holidays (Nager.Date),
 * null for international days.
 */
class CalendarEvent extends Model
{
    use HasFactory, SoftDeletes;

    /** Nepal's dates come from our official list, never from Nager.Date. */
    public const NEPAL = 'NP';

    protected $table = 'shq_events';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (CalendarEvent $event): void {
            if ($event->isDirty(['starts_on', 'ends_on', 'name']) && ! $event->isDirty('verified_at')) {
                $event->verified_at = null;
                $event->verified_by = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'category' => EventCategory::class,
            'region' => EventRegion::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'import_date' => 'immutable_date',
            'public_holiday' => 'boolean',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Events a brand sees: the account's shared dates plus its own.
     *
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeVisibleTo(Builder $query, Workspace $workspace): void
    {
        $query->where('account_id', $workspace->account_id)
            ->where(fn (Builder $query) => $query->whereNull('workspace_id')->orWhere('workspace_id', $workspace->id));
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): void
    {
        $query->where('starts_on', '<=', $to)->where('ends_on', '>=', $from);
    }

    /**
     * Events for an audience in these countries: theirs, plus every event
     * without a country (international days, a brand's own dates). Null
     * keeps every country.
     *
     * @param  Builder<CalendarEvent>  $query
     * @param  list<string>|null  $countries  ISO 3166-1 alpha-2 codes
     */
    public function scopeForCountries(Builder $query, ?array $countries): void
    {
        if ($countries === null) {
            return;
        }

        $codes = collect($countries)->map(fn (string $code): string => strtoupper(trim($code)))->filter()->unique()->values()->all();

        $query->where(fn (Builder $query) => $query->whereNull('country_code')->when($codes !== [], fn (Builder $query) => $query->orWhereIn('country_code', $codes)));
    }

    /**
     * Events of our official list (Nepal's dates and the international
     * days), which the CSV importer owns; other countries' holidays are
     * Nager.Date's.
     *
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeFromOfficialList(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('country_code')->orWhere('country_code', self::NEPAL));
    }

    /**
     * @param  Builder<CalendarEvent>  $query
     */
    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }
}
