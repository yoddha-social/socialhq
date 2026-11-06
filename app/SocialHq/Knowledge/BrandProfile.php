<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's brand: what it is, who it talks to and how. One per
 * workspace; every AI writing feature reads it (BrandKnowledge).
 */
class BrandProfile extends Model
{
    public const FIELDS = ['about', 'audience', 'voice', 'words_to_use', 'words_to_avoid'];

    protected $table = 'shq_brand_profiles';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            // Content pillars, in the brand's order (their colours follow it).
            'pillars' => 'array',
            // Posts per week, keyed by social account id; read by the planner.
            'cadence' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
