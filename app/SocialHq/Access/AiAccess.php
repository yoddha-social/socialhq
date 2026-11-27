<?php

declare(strict_types=1);

namespace App\SocialHq\Access;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Whether a person may call AI in a workspace: their role's "Use AI", then
 * TryPost's account-wide `useAi` gate (app access on the hosted plan). Every
 * AI entry point asks here instead of the gate alone.
 */
final class AiAccess
{
    public static function inspect(User $user, Workspace $workspace): Response
    {
        if (! $user->hasPermissionIn($workspace, Permission::AiUse)) {
            return Response::denyWithStatus(HttpResponse::HTTP_FORBIDDEN, __('socialhq_roles.denied.ai'));
        }

        return Gate::forUser($user)->inspect('useAi', $workspace->account);
    }

    public static function allows(User $user, Workspace $workspace): bool
    {
        return self::inspect($user, $workspace)->allowed();
    }

    /**
     * The JSON answer when the call may not run: 403 when the role can't use
     * AI, TryPost's 402 when the account may not; null when it may.
     */
    public static function refusal(User $user, Workspace $workspace): ?JsonResponse
    {
        $gate = self::inspect($user, $workspace);

        if ($gate->allowed()) {
            return null;
        }

        return response()->json(['message' => $gate->message()], $gate->status() ?? HttpResponse::HTTP_PAYMENT_REQUIRED);
    }
}
