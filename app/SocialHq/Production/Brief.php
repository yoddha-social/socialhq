<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\PlanChanged;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * The month's cards show the brief's stage: open plan pages reload it
     * (`PlanChanged`).
     */
    protected static function booted(): void
    {
        static::saved(fn (self $brief) => PlanChanged::dispatchFor(self::planIdOf($brief)));
        static::deleted(fn (self $brief) => PlanChanged::dispatchFor(self::planIdOf($brief)));
    }

    private static function planIdOf(self $brief): ?int
    {
        $planId = PlanItem::query()->whereKey($brief->item_id)->value('plan_id');

        return $planId === null ? null : (int) $planId;
    }

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

    /**
     * Number the briefs 0, 1, 2… in the order given, in one query.
     *
     * @param  list<int>  $ids
     */
    public static function reorder(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Integers only (ids and positions), so the CASE is built inline.
        $cases = collect(array_values($ids))->map(fn (int $id, int $position): string => 'when id = '.$id.' then '.$position)->implode(' ');

        static::query()->whereKey($ids)->update(['position' => DB::raw("case {$cases} end")]);
    }

    public function currentRound(): int
    {
        return (int) BriefFile::query()->where('brief_id', $this->id)->max('round');
    }
}
