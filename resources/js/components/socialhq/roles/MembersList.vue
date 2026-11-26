<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    IconClock,
    IconLock,
    IconTrash,
    IconUserMinus,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ChangeRoleDialog from '@/components/socialhq/roles/ChangeRoleDialog.vue';
import RolePicker from '@/components/socialhq/roles/RolePicker.vue';
import type {
    CatalogGroup,
    CompanyRole,
} from '@/components/socialhq/roles/roles';
import TransSlots from '@/components/TransSlots.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { destroy as destroyInvite } from '@/routes/app/invites';
import { remove as removeMemberRoute } from '@/routes/app/members';
import { index as rolesIndex } from '@/routes/socialhq/roles';
import type { WorkspaceInvitation, WorkspaceMember } from '@/types/members';

const props = defineProps<{
    members: WorkspaceMember[];
    invitations: WorkspaceInvitation[];
    roles: CompanyRole[];
    catalog: CatalogGroup[];
    ownerId: string | null;
    workspaceName: string;
}>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);
const { canManageTeam } = useWorkspaceAbilities();

const removeMemberModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);
const cancelInvitationModal = ref<InstanceType<
    typeof ConfirmDeleteModal
> | null>(null);

const isOwner = (member: WorkspaceMember): boolean =>
    member.id === props.ownerId;
const isSelf = (member: WorkspaceMember): boolean =>
    member.id === currentUserId.value;

// Locked rows show the role as text with the reason on hover: the owner's,
// your own, and anyone with access you don't hold (the server's `manageable`).
const lockedReasonKey = (member: WorkspaceMember): string | null => {
    if (isOwner(member)) {
        return 'socialhq_roles.members.locked.owner';
    }

    if (isSelf(member)) {
        return 'socialhq_roles.members.locked.self';
    }

    return member.manageable ? null : 'socialhq_roles.members.locked.above';
};

const roleLabelKey = (member: WorkspaceMember): string | null =>
    isOwner(member)
        ? 'socialhq_roles.badges.owner'
        : member.role
          ? null
          : 'socialhq_roles.members.no_role';

// "6 members · Owner 1 · Admin 1 · Editor 2", in the roles' order.
const breakdown = computed(() => {
    const counts = new Map<string, number>();

    for (const member of props.members) {
        if (!isOwner(member) && member.role) {
            counts.set(member.role.id, (counts.get(member.role.id) ?? 0) + 1);
        }
    }

    return props.roles
        .filter((role) => counts.has(role.id))
        .map((role) => ({ name: role.name, count: counts.get(role.id) ?? 0 }));
});
const hasOwnerRow = computed(() => props.members.some(isOwner));

const changing = ref<{ member: WorkspaceMember; to: CompanyRole } | null>(null);
const changeOpen = ref(false);

const openChangeRole = (member: WorkspaceMember, to: CompanyRole): void => {
    changing.value = { member, to };
    changeOpen.value = true;
};

const openRemoveMember = (member: WorkspaceMember): void => {
    removeMemberModal.value?.open({
        url: removeMemberRoute.url(member.id),
        confirmText: member.email,
    });
};

const openCancelInvitation = (invitation: WorkspaceInvitation): void => {
    cancelInvitationModal.value?.open({
        url: destroyInvite.url(invitation.id),
        confirmText: invitation.email,
    });
};
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-muted-foreground"
            data-testid="members-breakdown"
        >
            <span>{{
                $tChoice('socialhq_roles.members.count', members.length, {
                    count: String(members.length),
                })
            }}</span>
            <template v-if="hasOwnerRow">
                <span class="text-border">·</span>
                <span>{{
                    $t('socialhq_roles.members.breakdown', {
                        role: $t('socialhq_roles.badges.owner'),
                        count: '1',
                    })
                }}</span>
            </template>
            <template v-for="item in breakdown" :key="item.name">
                <span class="text-border">·</span>
                <span>{{
                    $t('socialhq_roles.members.breakdown', {
                        role: item.name,
                        count: String(item.count),
                    })
                }}</span>
            </template>
            <Link
                :href="rolesIndex()"
                class="ms-auto font-medium text-primary-text hover:underline"
                data-testid="members-roles-link"
            >
                {{ $t('socialhq_roles.members.what_roles_do') }} →
            </Link>
        </div>

        <ul class="flex flex-col gap-2">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex items-center gap-4 rounded-xl border border-border bg-card p-4"
                :data-testid="`member-row-${member.id}`"
            >
                <Avatar
                    :src="member.photo_url"
                    :name="member.name"
                    class="size-12 rounded-lg"
                    fallback-class="bg-sidebar-accent text-sidebar-accent-foreground"
                />
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <p
                        class="truncate text-sm leading-tight font-emphasis text-foreground"
                    >
                        {{ member.name }}
                        <span
                            v-if="isSelf(member)"
                            class="font-normal text-muted-foreground"
                            >{{ $t('socialhq_roles.members.you') }}</span
                        >
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ member.email }}
                    </p>
                </div>

                <span
                    v-if="lockedReasonKey(member)"
                    class="inline-flex h-9 shrink-0 items-center gap-1.5 px-3 text-[13px] font-medium text-muted-foreground"
                    :title="$t(lockedReasonKey(member) ?? '')"
                    :data-testid="`member-badge-${member.id}`"
                >
                    <IconLock class="size-3.5" />
                    {{
                        roleLabelKey(member)
                            ? $t(roleLabelKey(member) ?? '')
                            : member.role?.name
                    }}
                </span>
                <RolePicker
                    v-else
                    :member-id="member.id"
                    :member-name="member.name"
                    :current="member.role?.id ?? null"
                    :label="
                        member.role
                            ? member.role.name
                            : $t('socialhq_roles.members.no_role')
                    "
                    :roles="roles"
                    @pick="openChangeRole(member, $event)"
                />

                <span v-if="lockedReasonKey(member)" class="w-9 shrink-0" />
                <Button
                    v-else
                    variant="ghost"
                    size="icon"
                    class="shrink-0 text-muted-foreground"
                    :title="$t('socialhq_roles.members.remove')"
                    :aria-label="$t('socialhq_roles.members.remove')"
                    :data-testid="`member-remove-${member.id}`"
                    @click="openRemoveMember(member)"
                >
                    <IconUserMinus class="size-4" />
                </Button>
            </li>

            <li
                v-for="invitation in invitations"
                :key="`inv-${invitation.id}`"
                class="flex items-center gap-4 rounded-xl border border-border bg-card p-4"
                :data-testid="`invite-row-${invitation.id}`"
            >
                <span
                    class="inline-flex size-12 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <IconClock class="size-5" />
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <p
                        class="truncate text-sm leading-tight font-emphasis text-foreground"
                    >
                        {{ invitation.email }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        <TransSlots
                            v-if="invitation.role"
                            :message="
                                $t('socialhq_roles.members.invite_pending')
                            "
                        >
                            <template #role>
                                <strong class="font-medium text-foreground">{{
                                    invitation.role.name
                                }}</strong>
                            </template>
                        </TransSlots>
                        <template v-else>{{
                            $t('settings.members.pending.title')
                        }}</template>
                    </p>
                </div>
                <Button
                    v-if="canManageTeam"
                    variant="ghost"
                    size="icon"
                    class="shrink-0 text-destructive-text"
                    :aria-label="
                        $t('settings.members.cancel_invite_modal.action')
                    "
                    :data-testid="`invite-cancel-${invitation.id}`"
                    @click="openCancelInvitation(invitation)"
                >
                    <IconTrash class="size-4" />
                </Button>
            </li>
        </ul>

        <ChangeRoleDialog
            v-model:open="changeOpen"
            :member="
                changing
                    ? {
                          id: changing.member.id,
                          name: changing.member.name,
                          permissions: changing.member.permissions ?? [],
                      }
                    : null
            "
            :from-label="
                changing?.member.role?.name ??
                $t('socialhq_roles.members.no_role')
            "
            :to="changing?.to ?? null"
            :catalog="catalog"
            :workspace-name="workspaceName"
        />

        <ConfirmDeleteModal
            ref="removeMemberModal"
            :title="$t('settings.members.remove_modal.title')"
            :description="$t('settings.members.remove_modal.description')"
            :action="$t('settings.members.remove_modal.action')"
        />

        <ConfirmDeleteModal
            ref="cancelInvitationModal"
            :title="$t('settings.members.cancel_invite_modal.title')"
            :description="
                $t('settings.members.cancel_invite_modal.description')
            "
            :action="$t('settings.members.cancel_invite_modal.action')"
        />
    </div>
</template>
