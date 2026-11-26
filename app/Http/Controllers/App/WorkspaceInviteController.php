<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Invite\CreateInvite;
use App\Actions\Invite\DeleteInvite;
use App\Actions\Invite\RemoveMember;
use App\Http\Requests\App\Invite\StoreWorkspaceInviteRequest;
use App\Http\Requests\App\Invite\UpdateWorkspaceInviteRequest;
use App\Http\Requests\App\Invite\UpdateWorkspaceMemberRequest;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Access\AccessLog;
use App\SocialHq\Access\MemberRoles;
use App\SocialHq\Access\Permission;
use App\SocialHq\Access\Role;
use App\SocialHq\Access\RoleResource;
use App\SocialHq\Access\Roles;
use App\SocialHq\Access\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceInviteController extends Controller
{
    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        // Social HQ: each member's role, and the company's roles with the ones this person may give.
        $roles = Roles::forAccount($workspace->account_id)->keyBy('id');
        $assignable = MemberRoles::assignableIds($request->user(), $workspace);
        // The owner first, then by name: the order stays put when a role changes.
        $ownerId = $workspace->account?->owner_id;
        $members = $workspace->members()->with('avatarMedia')->orderBy('users.name')->get()
            ->sortBy(fn (User $member): int => $member->id === $ownerId ? 0 : 1)
            ->values();
        $manageable = MemberRoles::manageableIds($request->user(), $workspace, $members, $roles);

        return Inertia::render('settings/workspace/Members', [
            'workspace' => $workspace,
            'invites' => $workspace->invites()
                ->latest()
                ->get()
                ->map(fn (Invite $invite): array => [
                    ...$invite->toArray(),
                    'role' => RoleResource::summary($roles->get($invite->shq_role_id)),
                ]),
            'members' => $members
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'photo_url' => $member->photo_url,
                    'is_admin' => (bool) $member->pivot->is_admin,
                    'requires_approval' => (bool) $member->pivot->requires_approval,
                    'role' => RoleResource::summary($roles->get($member->pivot->shq_role_id)),
                    'manageable' => in_array($member->id, $manageable, true),
                    'permissions' => WorkspaceAccess::through($member, $workspace, $member->pivot, $roles)->permissions,
                ]),
            'roles' => $roles->values()->map(fn (Role $role): array => [
                ...RoleResource::make($role),
                'assignable' => in_array($role->id, $assignable, true),
            ])->all(),
            'catalog' => Permission::catalog(),
            'owner' => [
                'id' => $workspace->account?->owner?->id,
                'name' => $workspace->account?->owner?->name,
                'email' => $workspace->account?->owner?->email,
            ],
        ]);
    }

    public function store(StoreWorkspaceInviteRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('inviteMember', $workspace);

        $existingInvite = $workspace->invites()
            ->forEmail($request->email)
            ->first();

        if ($existingInvite) {
            return back()->withErrors([
                'email' => __('settings.members.errors.invite_exists'),
            ]);
        }

        // Social HQ: inviting yourself or a member says so. An address with an
        // account elsewhere gets the invite like any other, so the inviter never
        // learns which addresses use Social HQ in another company; accepting it
        // is refused there instead (AcceptInvite, Result::OtherAccount).
        if ($request->email === Str::lower($request->user()->email)) {
            return back()->withErrors([
                'email' => __('settings.members.flash.already_member'),
            ]);
        }

        if ($workspace->members()->whereLike('users.email', addcslashes($request->email, '%_\\'))->exists()) {
            return back()->withErrors([
                'email' => __('settings.members.errors.already_member'),
            ]);
        }

        // Social HQ: the invite's role, which the inviter must hold every permission of.
        $role = $request->memberRole($workspace);

        if (! MemberRoles::mayAssign($request->user(), $workspace, $role)) {
            return back()->withErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);
        }

        // Not in a transaction with the log: CreateInvite queues the email, which must find the invite.
        CreateInvite::execute($workspace, [...$request->validated(), ...Roles::membershipColumns($role)]);
        AccessLog::inviteSent($workspace, $request->email, $role);

        session()->flash('flash.banner', __('settings.members.flash.invite_sent'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    /**
     * Social HQ: changes a pending invite's role (or TryPost's flags, mapped
     * to a built-in role). Only an invite still waiting for this workspace
     * can change: an accepted one, or one for another workspace, is not
     * found. The rules of inviting hold: only someone who could have sent the
     * invite changes it, and only to a role they could give.
     */
    public function update(UpdateWorkspaceInviteRequest $request, Invite $invite): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $found = $this->manageableInvite($request, $workspace, $invite);
        $from = $this->inviteRole($found);

        if ($from !== null && ! MemberRoles::mayAssign($request->user(), $workspace, $from)) {
            return back()->withErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);
        }

        $role = $request->memberRole($workspace);

        if (! MemberRoles::mayAssign($request->user(), $workspace, $role)) {
            return back()->withErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);
        }

        $found->update(Roles::membershipColumns($role));

        if ($from?->id !== $role->id) {
            AccessLog::inviteRoleChanged($workspace, $found->email, $from, $role);
        }

        return back();
    }

    /**
     * Social HQ: the role a pending invite carries, null for one from before roles.
     */
    private function inviteRole(Invite $invite): ?Role
    {
        return $invite->shq_role_id === null ? null : Role::query()->find($invite->shq_role_id);
    }

    /**
     * Social HQ: a pending invite to the current
     * workspace whose every workspace the person manages, so an admin of
     * one brand never changes or cancels another brand's invite.
     */
    private function manageableInvite(Request $request, Workspace $workspace, Invite $invite): Invite
    {
        $found = $workspace->invites()->whereKey($invite->id)->firstOrFail();
        $manages = Workspace::query()->whereKey($found->workspaces ?? [])->get()
            ->every(fn (Workspace $listed): bool => $request->user()->can('manageTeam', $listed));

        abort_unless($manages, Response::HTTP_NOT_FOUND);

        return $found;
    }

    public function destroy(Request $request, Invite $invite): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        $found = $this->manageableInvite($request, $workspace, $invite);

        // Social HQ: only someone who could have sent it cancels an invite.
        $role = $this->inviteRole($found);

        if ($role !== null && ! MemberRoles::mayAssign($request->user(), $workspace, $role)) {
            return back()->withErrors(['invite' => __('socialhq_roles.errors.above_your_access')]);
        }

        DeleteInvite::execute($found);

        session()->flash('flash.banner', __('settings.members.flash.invite_deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function removeMember(Request $request, string $userId): RedirectResponse
    {
        // Social HQ: ids compare as the database does (a uuid in upper case is the same user).
        $userId = Str::lower($userId);
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        if ($userId === $request->user()->id) {
            return back()->withErrors(['member' => __('settings.members.errors.cannot_remove_self')]);
        }

        if ($userId === $workspace->account?->owner_id) {
            return back()->withErrors(['member' => __('settings.members.errors.cannot_remove_owner')]);
        }

        // Social HQ: only members are removed here (TryPost would also settle a
        // non-member of the account, e.g. someone waiting for access, which only
        // the owner declines), and nobody removes someone with access they don't hold.
        abort_unless($workspace->members()->whereKey($userId)->exists(), Response::HTTP_NOT_FOUND);

        if (! MemberRoles::mayManage($request->user(), $workspace, $userId)) {
            return back()->withErrors(['member' => __('socialhq_roles.errors.member_above_your_access')]);
        }

        // The log entry is read before (removing can delete the user) and written after it.
        $removal = AccessLog::removalOf($workspace, $userId);
        RemoveMember::execute($workspace, $userId);
        $removal();

        session()->flash('flash.banner', __('settings.members.flash.member_removed'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function updateMember(UpdateWorkspaceMemberRequest $request, string $userId): RedirectResponse
    {
        $userId = Str::lower($userId);
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        if ($userId === $request->user()->id) {
            return back()->withErrors(['is_admin' => __('settings.members.errors.cannot_change_own_access')]);
        }

        if ($userId === $workspace->account?->owner_id) {
            return back()->withErrors(['is_admin' => __('settings.members.errors.cannot_change_owner_access')]);
        }

        $workspace->members()->findOrFail($userId);

        // Social HQ: a role (or TryPost's flags, mapped to a built-in role). Nobody
        // changes someone with more access than they hold, or gives more than they hold.
        if (! MemberRoles::mayManage($request->user(), $workspace, $userId)) {
            return back()->withErrors(['role_id' => __('socialhq_roles.errors.member_above_your_access')]);
        }

        $role = $request->memberRole($workspace);

        if (! MemberRoles::mayAssign($request->user(), $workspace, $role)) {
            return back()->withErrors(['role_id' => __('socialhq_roles.errors.above_your_access')]);
        }

        Roles::assign($workspace, $userId, $role);

        session()->flash('flash.banner', __('socialhq_roles.flash.member_changed', [
            'name' => (string) User::query()->whereKey($userId)->value('name'),
            'role' => $role->name,
        ]));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
