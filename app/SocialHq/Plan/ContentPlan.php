<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand's content plan for one month: the items the team will post, drafted
 * by people or the AI, reviewed and approved by an admin.
 */
class ContentPlan extends Model
{
    use HasFactory;

    /** A running AI draft older than this is treated as dead. */
    public const STALE_MINUTES = 10;

    protected $table = 'shq_content_plans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'status' => PlanStatus::class,
            'generation_status' => GenerationStatus::class,
            'generation_rejections' => 'array',
            'generation_options' => 'array',
            'generated_at' => 'immutable_datetime',
            'generation_started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
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
     * @return HasMany<PlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PlanItem::class, 'plan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * An AI draft is running and started less than STALE_MINUTES ago. An
     * older run (a worker that died) no longer blocks a new one.
     */
    public function isGenerating(): bool
    {
        return $this->generation_status === GenerationStatus::Running
            && $this->generation_started_at !== null
            && $this->generation_started_at->greaterThan(now()->subMinutes(self::STALE_MINUTES));
    }

    public function hasOpenSuggestions(): bool
    {
        return $this->items()->where('status', ItemStatus::Suggested)->exists();
    }

    /**
     * Any change to an approved plan sends it back for review.
     */
    public function reopen(): void
    {
        if ($this->status === PlanStatus::Approved) {
            $this->forceFill(['status' => PlanStatus::InReview, 'approved_at' => null, 'approved_by' => null])->save();
        }
    }
}
