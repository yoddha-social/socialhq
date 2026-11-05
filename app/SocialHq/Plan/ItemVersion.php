<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of an item's copy. Versions are never edited: a change is a
 * new version, so reviews always point at the text they were about.
 */
class ItemVersion extends Model
{
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'shq_item_versions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => ItemSource::class,
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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
