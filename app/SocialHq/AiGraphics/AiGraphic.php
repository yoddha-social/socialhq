<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Generate with AI" request: who asked, what, and how it ended
 * (pending, generated, blocked by the vision check, or failed).
 */
class AiGraphic extends Model
{
    public const PENDING = 'pending';

    public const GENERATED = 'generated';

    public const BLOCKED = 'blocked';

    public const FAILED = 'failed';

    protected $table = 'shq_ai_graphics';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'format' => GraphicFormat::class,
            'brand_logo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
