<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an old calendar: what the team posted on a day, where, in which
 * format, and how it did when the sheet says so. The raw channel and format
 * text stay as written; platform and content_type are set only when they
 * could be recognised.
 */
class PastPost extends Model
{
    use HasFactory;

    protected $table = 'shq_past_posts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'posted_on' => 'immutable_date',
            'platform' => Platform::class,
            'content_type' => ContentType::class,
            'row_number' => 'integer',
            'engagement' => 'integer',
            'reach' => 'integer',
            'views' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CalendarImport, $this>
     */
    public function calendarImport(): BelongsTo
    {
        return $this->belongsTo(CalendarImport::class, 'calendar_import_id');
    }
}
