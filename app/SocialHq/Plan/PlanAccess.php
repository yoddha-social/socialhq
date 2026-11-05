<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may do what in the planning layer (plans, items, copy, briefs, events).
 * Any member who may create posts works on plans; approving (a plan, copy, a
 * final creative, an event date) follows TryPost's approvePosts ability, so
 * it matches who approves posts under the approval rules.
 */
final class PlanAccess
{
    public static function canWork(User $user, Workspace $workspace): bool
    {
        return Gate::forUser($user)->allows('createPost', $workspace);
    }

    public static function canApprove(User $user, Workspace $workspace): bool
    {
        return Gate::forUser($user)->allows('approvePosts', $workspace);
    }

    /**
     * Dates shared by every brand of the account (festivals, holidays, the
     * official list) change for all brands at once, so only the account owner
     * or someone who approves in every brand may add, edit, confirm or delete
     * them. Brand-only dates follow canApprove.
     */
    public static function canManageSharedEvents(User $user, Workspace $workspace): bool
    {
        if ($user->ownsAccountOf($workspace)) {
            return true;
        }

        $workspaces = Workspace::query()->where('account_id', $workspace->account_id)->get();

        return $workspaces->isNotEmpty() && $workspaces->every(fn (Workspace $brand): bool => self::canApprove($user, $brand));
    }

    public static function canManageBrand(User $user, Workspace $workspace): bool
    {
        return Gate::forUser($user)->allows('update', $workspace);
    }

    /**
     * The request's current workspace, after checking the user may work in it
     * (and approve, when asked).
     */
    public static function workspace(Request $request, bool $approve = false): Workspace
    {
        $user = $request->user();
        $workspace = $user?->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);
        abort_unless(
            $approve ? self::canApprove($user, $workspace) : self::canWork($user, $workspace),
            Response::HTTP_FORBIDDEN,
        );

        return $workspace;
    }

    /**
     * An item of the request's current workspace, after the same checks as
     * workspace(); an item of any other workspace is not found.
     */
    public static function item(Request $request, int $item, bool $approve = false): PlanItem
    {
        $workspace = self::workspace($request, $approve);

        return PlanItem::query()->where('workspace_id', $workspace->id)->findOrFail($item);
    }

    /**
     * What the pages need to show or hide actions.
     *
     * @return array{canWork: bool, canApprove: bool, canManageBrand: bool, canManageSharedEvents: bool}
     */
    public static function abilities(User $user, Workspace $workspace): array
    {
        $canApprove = self::canApprove($user, $workspace);

        return [
            'canWork' => self::canWork($user, $workspace),
            'canApprove' => $canApprove,
            'canManageBrand' => self::canManageBrand($user, $workspace),
            'canManageSharedEvents' => $canApprove && self::canManageSharedEvents($user, $workspace),
        ];
    }
}
