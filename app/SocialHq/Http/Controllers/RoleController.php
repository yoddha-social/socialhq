<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Access\MemberRoles;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleChanges;
use App\SocialHq\Access\RolePage;
use App\SocialHq\Access\RoleResource;
use App\SocialHq\Access\Roles;
use App\SocialHq\Http\Requests\Roles\DestroyRoleRequest;
use App\SocialHq\Http\Requests\Roles\DuplicateRoleRequest;
use App\SocialHq\Http\Requests\Roles\StoreRoleRequest;
use App\SocialHq\Http\Requests\Roles\UpdateRoleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Settings › Members › Roles. Whoever may give roles in the workspace sees
 * them; the company's roles are changed by the account owner only
 * (RoleAccess, checked by the requests). Giving roles to people is on the
 * Members page (WorkspaceInviteController).
 */
class RoleController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('socialhq/roles/Index', RolePage::props($request->user(), $this->workspace($request)));
    }

    public function compare(Request $request): InertiaResponse
    {
        return Inertia::render('socialhq/roles/Compare', RolePage::props($request->user(), $this->workspace($request)));
    }

    public function show(Request $request, string $role): InertiaResponse
    {
        $workspace = $this->workspace($request);
        $found = Role::query()->forAccount($workspace->account_id)->findOrFail($role);

        return Inertia::render('socialhq/roles/Show', [
            ...RolePage::props($request->user(), $workspace),
            'roleId' => $found->id,
        ]);
    }

    /**
     * The roles the invite dialog offers, wherever it opens (the sidebar, the
     * command palette, the members page), with the ones this person may give.
     */
    public function assignable(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request, 'inviteMember');
        $assignable = MemberRoles::assignableIds($request->user(), $workspace);

        return response()->json([
            'roles' => Roles::forAccount($workspace->account_id)
                ->map(fn (Role $role): array => [
                    ...RoleResource::make($role),
                    'assignable' => in_array($role->id, $assignable, true),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = RoleChanges::create(
            (string) $request->user()->account_id,
            (string) $request->validated('name'),
            $request->validated('description'),
            $request->permissions(),
        );

        return $this->flash(redirect()->route('socialhq.roles.show', $role->id), __('socialhq_roles.flash.created', ['name' => $role->name]));
    }

    public function duplicate(DuplicateRoleRequest $request): RedirectResponse
    {
        $copy = RoleChanges::duplicate($request->role());

        return $this->flash(redirect()->route('socialhq.roles.show', $copy->id), __('socialhq_roles.flash.duplicated', ['name' => $copy->name]));
    }

    public function update(UpdateRoleRequest $request): RedirectResponse
    {
        $role = RoleChanges::update($request->role(), $request->changes());

        return $this->flash(back(), trans_choice('socialhq_roles.flash.updated', $role->membershipCount()));
    }

    public function destroy(DestroyRoleRequest $request): RedirectResponse
    {
        $moveTo = $request->moveTo();
        $moved = RoleChanges::delete($request->role(), $moveTo);

        return $this->flash(
            redirect()->route('socialhq.roles.index'),
            trans_choice('socialhq_roles.flash.deleted', $moved, ['role' => $moveTo?->name ?? '']),
        );
    }

    /**
     * The current workspace, which the person must be allowed to give roles in.
     */
    private function workspace(Request $request, string $ability = 'manageTeam'): Workspace
    {
        $workspace = $request->user()->currentWorkspace;

        abort_if($workspace === null, 404);
        $this->authorize($ability, $workspace);

        return $workspace;
    }

    private function flash(RedirectResponse $redirect, string $message): RedirectResponse
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return $redirect;
    }
}
