<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use App\Models\Account;
use App\Models\User;
use App\SocialHq\Ai\Company\CompanyRoute;
use App\SocialHq\Ai\Company\CompanyRouting;
use Laravel\Ai\Exceptions\AiException;

/**
 * No approved provider can serve the task. An AiException, so the
 * controllers that already catch the SDK's errors show their usual message.
 *
 * Two kinds: a configuration state (the company has no AI, or none that
 * serves the task), made by needsSetup() or forTask(), whose message tells
 * people how to set AI up and which is never reported as an error; and a
 * misconfiguration of the platform (a provider that is not approved), whose
 * message is for developers.
 */
class AiUnavailableException extends AiException
{
    /** The lang key (without .owner/.member) of a configuration state's copy. */
    private ?string $copy = null;

    private ?Account $account = null;

    /**
     * The company has no AI of its own and may not use the platform's. The
     * owner is told where to add a provider; others, who to ask.
     */
    public static function needsSetup(CompanyRoute $route, ?User $user = null): self
    {
        return self::configurationState('socialhq_ai.unavailable', $route->account, $user);
    }

    /**
     * The task can't run for the current company: it has no AI at all
     * (needsSetup), or none of its AI serves this task.
     */
    public static function forTask(AiTask $task, ?User $user = null): self
    {
        $route = CompanyRouting::current();

        return $route->kind === CompanyRoute::NONE
            ? self::needsSetup($route, $user)
            : self::configurationState('socialhq.ai.task_unavailable', $route->account, $user);
    }

    /**
     * Whether this is a configuration state (people see how to set AI up)
     * rather than a failure to report.
     */
    public function isConfigurationState(): bool
    {
        return $this->copy !== null;
    }

    /**
     * The message for someone other than the signed-in user, such as the
     * person who started a job (a job has no signed-in user, so the message
     * it was thrown with always names the owner as someone else).
     */
    public function messageFor(?User $user): string
    {
        return $this->copy === null ? $this->getMessage() : self::copyFor($this->copy, $this->account, $user);
    }

    private static function configurationState(string $copy, ?Account $account, ?User $user): self
    {
        $exception = new self(self::copyFor($copy, $account, $user ?? auth()->user()));
        $exception->copy = $copy;
        $exception->account = $account;

        return $exception;
    }

    private static function copyFor(string $copy, ?Account $account, mixed $user): string
    {
        $isOwner = $user instanceof User && $account !== null && $account->owner_id === $user->id;

        return __($copy.($isOwner ? '.owner' : '.member'));
    }
}
