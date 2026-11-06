<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use App\Models\User;
use App\SocialHq\Ai\Company\CompanyRoute;
use Laravel\Ai\Exceptions\AiException;

/**
 * No approved provider can serve the task. An AiException, so the
 * controllers that already catch the SDK's errors show their usual message.
 */
class AiUnavailableException extends AiException
{
    /**
     * The company has no AI of its own and may not use the platform's. The
     * owner is told where to add a provider; others, who to ask.
     */
    public static function needsSetup(CompanyRoute $route): self
    {
        $user = auth()->user();
        $isOwner = $user instanceof User && $route->account !== null && $route->account->owner_id === $user->id;

        return new self(__($isOwner ? 'socialhq_ai.unavailable.owner' : 'socialhq_ai.unavailable.member'));
    }
}
