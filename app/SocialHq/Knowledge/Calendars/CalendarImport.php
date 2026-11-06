<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One old content calendar (a CSV or XLSX file) brought into a brand's
 * knowledge. Uploaded first (pending, the file on the private disk), then
 * imported with the team's column mapping: its rows become past posts and
 * the file is deleted.
 */
class CalendarImport extends Model
{
    use HasFactory;

    protected $table = 'shq_calendar_imports';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => CalendarImportStatus::DEFAULT->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => CalendarImportStatus::class,
            'date_order' => DateOrder::class,
            'column_mapping' => 'array',
            'skipped_reasons' => 'array',
            'sheet' => 'integer',
            'rows_imported' => 'integer',
            'rows_skipped' => 'integer',
            'first_date' => 'immutable_date',
            'last_date' => 'immutable_date',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PastPost, $this>
     */
    public function pastPosts(): HasMany
    {
        return $this->hasMany(PastPost::class, 'calendar_import_id');
    }

    /**
     * @param  Builder<CalendarImport>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', CalendarImportStatus::Pending);
    }

    /**
     * @param  Builder<CalendarImport>  $query
     */
    public function scopeImported(Builder $query): void
    {
        $query->where('status', CalendarImportStatus::Imported);
    }
}
