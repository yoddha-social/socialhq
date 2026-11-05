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

    protected $table = 'shq_content_plans';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'status' => PlanStatus::class,
            'generation_status' => GenerationStatus::class,
            'generation_rejections' => 'array',
            'generated_at' => 'immutable_datetime',
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
     * Any change to an approved plan sends it back for review.
     */
    public function reopen(): void
    {
        if ($this->status === PlanStatus::Approved) {
            $this->forceFill(['status' => PlanStatus::InReview, 'approved_at' => null, 'approved_by' => null])->save();
        }
    }
}
