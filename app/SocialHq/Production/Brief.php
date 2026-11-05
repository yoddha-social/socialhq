<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the creative team makes for a plan item: a graphic or a video, with
 * its format, text, direction and references. Its stage is its column on the
 * production board.
 */
class Brief extends Model
{
    use HasFactory;

    protected $table = 'shq_briefs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => BriefKind::class,
            'stage' => BriefStage::class,
            'due_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
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
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return HasMany<BriefFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(BriefFile::class)->orderBy('round')->orderBy('id');
    }

    public function currentRound(): int
    {
        return (int) $this->files()->max('round');
    }
}
