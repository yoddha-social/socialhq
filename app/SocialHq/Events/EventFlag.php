<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team member's note that a date looks wrong ("Tihar is on the 1st, not the
 * 2nd"), for the brand's approvers. It is open until an approver confirms,
 * edits or hides the date, or marks the note as handled.
 */
class EventFlag extends Model
{
    protected $table = 'shq_event_flags';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    /**
     * @param  Builder<EventFlag>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * Marks the brand's open notes on a date as handled, by the approver who
     * just confirmed, changed or hid it.
     */
    public static function resolveFor(int $eventId, string $workspaceId, string $userId): void
    {
        self::query()->where('event_id', $eventId)->where('workspace_id', $workspaceId)->open()
            ->update(['resolved_at' => now(), 'resolved_by' => $userId, 'updated_at' => now()]);
    }
}
