import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { WorkspacePermission } from '@/types';

/**
 * Capability flags for the current workspace, mirroring the backend
 * `WorkspacePolicy` abilities. Reads `auth.currentWorkspace`, whose
 * `permissions` are the member's role (Social HQ roles; the owner holds all).
 */
export const useWorkspaceAbilities = () => {
    const page = usePage();

    const workspace = computed(() => page.props.auth?.currentWorkspace ?? null);
    const permissions = computed(
        () => new Set<WorkspacePermission>(workspace.value?.permissions ?? []),
    );
    const can = (permission: WorkspacePermission) =>
        computed(() => permissions.value.has(permission));

    const isOwner = computed(() => workspace.value?.is_owner === true);
    const canManageWorkspace = can('workspace.settings');
    const requiresApproval = computed(
        () => !isOwner.value && workspace.value?.requires_approval === true,
    );
    const canPublishDirectly = computed(
        () =>
            workspace.value !== null &&
            !requiresApproval.value &&
            permissions.value.has('posts.publish'),
    );

    return {
        isOwner,
        role: computed(() => workspace.value?.role ?? null),
        canManageWorkspace,
        canPublishDirectly,
        canApprove: can('posts.approve'),
        requiresApproval,
        canCreatePost: can('posts.create'),
        canManageRepurposes: computed(
            () =>
                canPublishDirectly.value &&
                permissions.value.has('repurpose.manage'),
        ),
        canManageAccounts: can('channels.manage'),
        canManageWebhooks: can('webhooks.manage'),
        canManageApiKeys: can('api_keys.manage'),
        canManageTeam: can('team.manage'),
        canEditBrand: can('brand.edit'),
        canWorkOnPlans: can('plan.edit'),
        canManageBilling: isOwner,
        canCreateWorkspace: isOwner,
    };
};
