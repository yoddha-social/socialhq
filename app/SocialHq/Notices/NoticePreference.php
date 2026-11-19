<?php

declare(strict_types=1);

namespace App\SocialHq\Notices;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a person turned off for the bell. TryPost's `notification_preferences`
 * keeps the email side; this row keeps the "In the app" side and the live
 * notices switch.
 *
 * @property string $user_id
 * @property array<string, bool> $in_app
 * @property bool $live
 */
class NoticePreference extends Model
{
    protected $table = 'shq_notice_preferences';

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'in_app', 'live'];

    protected $attributes = ['in_app' => '{}', 'live' => true];

    protected function casts(): array
    {
        return [
            'in_app' => 'array',
            'live' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
