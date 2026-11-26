<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SEO keyword of a brand: main (any post may get it) or in the library,
 * with its tags (KeywordTag). Written only through BrandKeywords::save(),
 * which keeps a keyword's row (and so the day it was added) while it stays
 * in the list.
 */
class Keyword extends Model
{
    protected $table = 'shq_keywords';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'main' => 'boolean',
            'position' => 'integer',
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
     * @return HasMany<KeywordTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(KeywordTag::class)->orderBy('id');
    }
}
