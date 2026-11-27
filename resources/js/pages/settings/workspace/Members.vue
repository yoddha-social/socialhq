<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { ref } from 'vue';

import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import AccessRequestsBanner from '@/components/socialhq/AccessRequestsBanner.vue';
import MembersList from '@/components/socialhq/roles/MembersList.vue';
import type {
    CatalogGroup,
    CompanyRole,
} from '@/components/socialhq/roles/roles';
import RolesPageShell from '@/components/socialhq/roles/RolesPageShell.vue';
import { Button } from '@/components/ui/button';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import type { WorkspaceInvitation, WorkspaceMember } from '@/types/members';

defineProps<{
    workspace: { id: string; name: string };
    owner: { id: string | null; name: string | null; email: string | null };
    members: WorkspaceMember[];
    invites: WorkspaceInvitation[];
    // Social HQ: the company's roles (with the ones the viewer may give) and the permission catalog.
    roles: CompanyRole[];
    catalog: CatalogGroup[];
}>();

const { canManageTeam } = useWorkspaceAbilities();
const inviteOpen = ref(false);

const openInviteDialog = (): void => {
    inviteOpen.value = true;
};

</script>

<template>
    <Head :title="$t('settings.members.title')" />

    <!-- Social HQ: members with their role (Settings › Members › Roles, PATCHES.md). -->
    <SettingsLayout>
        <RolesPageShell
            width="members"
            :title="$t('settings.members.title')"
            :description="
                $t('socialhq_roles.members.description', {
                    workspace: workspace.name,
                })
            "
        >
            <template v-if="canManageTeam" #actions>
                <Button
                    data-testid="invite-member-button"
                    @click="openInviteDialog"
                >
                    <IconPlus class="size-4" />
                    {{ $t('settings.members.invite.submit') }}
                </Button>
            </template>

            <AccessRequestsBanner />

            <MembersList
                :members="members"
                :invitations="invites"
                :roles="roles"
                :catalog="catalog"
                :owner-id="owner.id"
                :workspace-name="workspace.name"
            />

            <InviteMemberDialog v-model:open="inviteOpen" :roles="roles" />
        </RolesPageShell>
    </SettingsLayout>
</template>
