<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { IconClock } from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import MemberAccessFields from '@/components/members/MemberAccessFields.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { update as updateInvite } from '@/routes/app/invites';
import type { WorkspaceInvitation } from '@/types/members';

// Social HQ: change a pending invite's access before it is accepted, with
// the same fields as editing a member (TryPost could only cancel it).

const props = defineProps<{ invitation: WorkspaceInvitation | null }>();

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const isAdmin = ref(false);
const requiresApproval = ref(false);

const loadAccess = (): void => {
    isAdmin.value = props.invitation?.is_admin ?? false;
    requiresApproval.value = props.invitation?.requires_approval ?? false;
};

watch(() => props.invitation, loadAccess, { immediate: true });

watch(open, (isOpen) => {
    if (isOpen) {
        loadAccess();
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

                <MemberAccessFields
                    v-model:is-admin="isAdmin"
                    v-model:requires-approval="requiresApproval"
                />
                <InputError
                    :message="errors.is_admin ?? errors.requires_approval"
                />

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
                        data-testid="edit-invite-submit"
                    >
                        {{ $t('settings.members.edit.submit') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
