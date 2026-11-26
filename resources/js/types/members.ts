import type { WorkspacePermission, WorkspaceRoleSummary } from '@/types';

export interface MemberAccess {
    is_admin: boolean;
    requires_approval: boolean;
}

export interface WorkspaceMember extends MemberAccess {
    id: string;
    name: string;
    email: string;
    photo_url: string | null;
    /** Social HQ: the member's role here (null for a membership from before roles). */
    role?: WorkspaceRoleSummary | null;
    /** Social HQ: what they may do here (the owner: everything). */
    permissions?: WorkspacePermission[];
    /** Social HQ: whether the viewer may change their role or remove them. */
    manageable?: boolean;
}

export interface WorkspaceInvitation extends MemberAccess {
    id: string;
    email: string;
    /** Social HQ: the role the invite joins with. */
    role?: WorkspaceRoleSummary | null;
}

export const memberAccessLabelKey = (
    access: MemberAccess,
    isOwner = false,
): string => {
    if (isOwner) {
        return 'settings.members.roles.owner';
    }

    if (access.is_admin) {
        return 'settings.members.roles.admin';
    }

    return access.requires_approval
        ? 'settings.members.access.needs_approval'
        : 'settings.members.roles.member';
};
