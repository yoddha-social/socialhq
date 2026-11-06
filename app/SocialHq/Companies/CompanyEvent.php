<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a company's history: who asked or decided what, and when.
 */
class CompanyEvent extends Model
{
    public const REQUESTED = 'requested';

    public const ASKED_AGAIN = 'asked_again';

    public const RENAMED = 'renamed';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public const SUSPENDED = 'suspended';

    public const RESTORED = 'restored';

    public const PLATFORM_AI_ON = 'platform_ai_on';

    public const PLATFORM_AI_OFF = 'platform_ai_off';

    public const UPDATED_AT = null;

    protected $table = 'shq_company_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
