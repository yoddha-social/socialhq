<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Models\User;
use App\SocialHq\Production\Brief;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment, a change request or an approval in an item's review thread,
 * about a copy version or a creative round.
 */
class ItemReview extends Model
{
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'shq_item_reviews';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => ReviewKind::class,
        ];
    }

    /**
     * @return BelongsTo<PlanItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<ItemVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ItemVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<Brief, $this>
     */
    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
