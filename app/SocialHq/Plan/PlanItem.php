<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Production\Brief;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One planned piece of content: a date, a channel and format, what it is
 * about, and the work that turns it into a post (copy, brief, creative).
 */
class PlanItem extends Model
{
    use HasFactory;

    protected $table = 'shq_plan_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_on' => 'immutable_date',
            'content_type' => ContentType::class,
            'creative' => CreativeKind::class,
            'status' => ItemStatus::class,
            'source' => ItemSource::class,
            'copy_status' => CopyStatus::class,
            'post_created_at' => 'immutable_datetime',
            'creative_waived_at' => 'immutable_datetime',
            'unsupported_details' => 'array',
        ];
    }

    /**
     * Starting work on an AI suggestion (its copy or brief) accepts it, so a
     * new AI draft, which replaces open suggestions, never deletes that work.
     */
    public function acceptForWork(): void
    {
        if ($this->status !== ItemStatus::Suggested) {
            return;
        }

        $this->update(['status' => ItemStatus::Planned]);
        $this->plan?->reopen();
    }

    /**
     * @return BelongsTo<ContentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContentPlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * @return BelongsTo<CalendarEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The approver who let the post go out text-only.
     *
     * @return BelongsTo<User, $this>
     */
    public function creativeWaivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creative_waived_by');
    }

    /**
     * @return HasMany<ItemVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ItemVersion::class, 'item_id')->orderBy('number');
    }

    /**
     * @return HasOne<ItemVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(ItemVersion::class, 'item_id')->ofMany('number', 'max');
    }

    /**
     * @return HasMany<ItemReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ItemReview::class, 'item_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasOne<Brief, $this>
     */
    public function brief(): HasOne
    {
        return $this->hasOne(Brief::class, 'item_id');
    }

    /**
     * The posts made from this item, linked when the composer saved them
     * (ItemPosts). A deleted post drops out.
     *
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, ItemPosts::TABLE, 'plan_item_id', 'post_id');
    }

    /**
     * Whether a post made from the item still exists. `post_created_at` only
     * says when the first one was saved: it stays when that post is deleted.
     */
    public function hasPosts(): bool
    {
        return $this->relationLoaded('posts') ? $this->posts->isNotEmpty() : $this->posts()->exists();
    }
}
