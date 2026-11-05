<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file uploaded for a brief in one review round. The approved round's
 * files are the final creative.
 */
class BriefFile extends Model
{
    use HasFactory;

    public const null UPDATED_AT = null;

    protected $table = 'shq_brief_files';

    protected $guarded = ['id'];

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
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
