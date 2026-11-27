<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { IconClock } from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import RoleChoiceList from '@/components/socialhq/roles/RoleChoiceList.vue';
import type { CompanyRole } from '@/components/socialhq/roles/roles';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { update as updateInvite } from '@/routes/app/invites';
import type { WorkspaceInvitation } from '@/types/members';

// Social HQ: change the role a pending invite joins with before it is
// accepted, with the same choices as inviting (TryPost could only cancel it).

const props = defineProps<{
    invitation: WorkspaceInvitation | null;
    roles: CompanyRole[];
}>();

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const roleId = ref<string | null>(null);

const isAvailable = (role: CompanyRole): boolean => role.assignable === true;

const loadRole = (): void => {
    roleId.value = props.invitation?.role?.id ?? null;
};

watch(() => props.invitation, loadRole, { immediate: true });

watch(open, (isOpen) => {
    if (isOpen) {
        loadRole();
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="edit-invite-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('settings.members.edit_invite.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ $t('settings.members.edit_invite.description') }}
                </DialogDescription>
            </DialogHeader>
            <Form
                v-if="invitation"
                v-bind="updateInvite.form(invitation.id)"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="closeDialog"
            >
                <div
                    class="flex items-center gap-3 rounded-xl border border-border p-3"
                >
                    <span
                        class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                    >
                        <IconClock class="size-5" aria-hidden="true" />
                    </span>
                    <div class="flex min-w-0 flex-col">
                        <p
                            class="truncate text-sm font-emphasis text-foreground"
                            data-testid="edit-invite-email"
                        >
                            {{ invitation.email }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ $t('settings.members.pending.title') }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label id="edit-invite-role-label">{{
                        $t('socialhq_roles.invite.role')
                    }}</Label>
                    <RoleChoiceList
                        v-model="roleId"
                        :roles="roles"
                        :available="isAvailable"
                        labelled-by="edit-invite-role-label"
                    />
                    <input type="hidden" name="role_id" :value="roleId ?? ''" />
                    <InputError
                        :message="
                            errors.role_id ??
                            errors.is_admin ??
                            errors.requires_approval
                        "
                    />
                </div>

                <DialogFooter>
                    <Button
                        variant="ghost"
                        type="button"
                        data-testid="edit-invite-cancel"
                        @click="closeDialog"
                    >
                        {{ $t('settings.members.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="processing"
                        :disabled="!roleId"
                        data-testid="edit-invite-submit"
                    >
                        {{ $t('settings.members.edit.submit') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
