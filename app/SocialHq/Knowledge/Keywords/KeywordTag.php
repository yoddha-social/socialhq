<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\SocialHq\Knowledge\KnowledgeEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tag of a keyword (KeywordTagKind): a pillar by name (`value`), a
 * product or service by its entry (`entry_id`, the tag goes with the
 * entry), an event by its occasion key (`value`, the same every year, see
 * Occasion; `label` is its name when tagged) or a range of months
 * (`month_from`, `month_to`).
 */
class KeywordTag extends Model
{
    protected $table = 'shq_keyword_tags';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => KeywordTagKind::class,
            'month_from' => 'integer',
            'month_to' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Keyword, $this>
     */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /**
     * @return BelongsTo<KnowledgeEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }
}
