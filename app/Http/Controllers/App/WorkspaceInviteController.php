<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\AccessToken\RevokeWorkspaceApiKeys;
use App\Actions\Invite\CreateInvite;
use App\Actions\Invite\DeleteInvite;
use App\Actions\Invite\RemoveMember;
use App\Http\Requests\App\Invite\StoreWorkspaceInviteRequest;
use App\Http\Requests\App\Invite\UpdateWorkspaceInviteRequest;
use App\Http\Requests\App\Invite\UpdateWorkspaceMemberRequest;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
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

        return Inertia::render('settings/workspace/Members', [
            'workspace' => $workspace,
            'invites' => $workspace->invites()
                ->latest()
                ->get(),
            'members' => $workspace->members()
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'photo_url' => $member->photo_url,
                    'is_admin' => (bool) $member->pivot->is_admin,
                    'requires_approval' => (bool) $member->pivot->requires_approval,
                ]),
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

        CreateInvite::execute($workspace, [...$request->validated(), ...$request->memberAccess()]);

        session()->flash('flash.banner', __('settings.members.flash.invite_sent'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    /**
     * Social HQ: changes a pending invite's access (admin, needs approval).
     * Only an invite still waiting for this workspace can change: an accepted
     * one, or one for another workspace, is not found.
     */
    public function update(UpdateWorkspaceInviteRequest $request, Invite $invite): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->manageableInvite($request, $workspace, $invite)->update($request->memberAccess());

        return back();
    }

    /**
     * Social HQ (PATCHES.md row 270): a pending invite to the current
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

        DeleteInvite::execute($this->manageableInvite($request, $workspace, $invite));

        session()->flash('flash.banner', __('settings.members.flash.invite_deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function removeMember(Request $request, string $userId): RedirectResponse
    {
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

        RemoveMember::execute($workspace, $userId);

        session()->flash('flash.banner', __('settings.members.flash.member_removed'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function updateMember(UpdateWorkspaceMemberRequest $request, string $userId): RedirectResponse
    {
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

        $access = $request->memberAccess();

        $workspace->members()->updateExistingPivot($userId, $access);

        RevokeWorkspaceApiKeys::forUserUnlessAdmin($userId, $workspace, $access['is_admin']);

        return back();
    }
}
