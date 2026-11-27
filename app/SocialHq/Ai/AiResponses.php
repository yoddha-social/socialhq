<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\AiAccess;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The JSON answers of the endpoints that run an AI call while the person
 * waits (TryPost's assistant, alt text and idea generator, our copy and brief
 * writers), so "AI isn't set up" and "the AI failed" read the same everywhere.
 *
 * AI that isn't set up for the company (or doesn't serve the task) is a 409
 * with copy that says how to set it up, never reported: it is a
 * configuration state, not an error. Any other failure is reported and
 * answered with the endpoint's own 502 message.
 */
final class AiResponses
{
    /**
     * The 409 when the task can't run for the current company, else null.
     */
    public static function unavailable(AiTask $task): ?JsonResponse
    {
        return AiRouter::isAvailable($task) ? null : self::notSetUp(AiUnavailableException::forTask($task));
    }

    /**
     * Before an AI call in the workspace: the 409 when the task can't run,
     * TryPost's 402 when the account may not use AI (the useAi gate), else
     * null. Pages decide whether to show the button with isUsable().
     */
    public static function refusal(AiTask $task, User $user, Workspace $workspace): ?JsonResponse
    {
        if (($unavailable = self::unavailable($task)) !== null) {
            return $unavailable;
        }

        return AiAccess::refusal($user, $workspace);
    }

    /**
     * Whether a page shows the task's AI button: refusal() would let it run.
     */
    public static function isUsable(AiTask $task, User $user, Workspace $workspace): bool
    {
        return AiRouter::isAvailable($task) && AiAccess::allows($user, $workspace);
    }

    /**
     * The answer to an AI call that threw.
     */
    public static function failed(Throwable $exception, string $message): JsonResponse
    {
        if ($exception instanceof AiUnavailableException && $exception->isConfigurationState()) {
            return self::notSetUp($exception);
        }

        report($exception);

        return response()->json(['message' => $message], Response::HTTP_BAD_GATEWAY);
    }

    private static function notSetUp(AiUnavailableException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
    }
}
