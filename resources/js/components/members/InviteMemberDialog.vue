<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { IconCircleCheck } from '@tabler/icons-vue';
import { nextTick, ref, useTemplateRef, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import MemberAccessFields from '@/components/members/MemberAccessFields.vue';
import TransSlots from '@/components/TransSlots.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store as storeInvite } from '@/routes/app/invites';

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const email = ref('');
const isAdmin = ref(false);
const requiresApproval = ref(false);

// Social HQ: after sending, the dialog stays open and says who got the
// invite, with "Invite another" and "Done" (the toast was easy to miss).
const sentTo = ref<string | null>(null);
const doneButton = useTemplateRef<{ $el: HTMLElement }>('doneButton');
const emailInput = useTemplateRef<{ $el: HTMLElement }>('emailInput');

const resetForm = (): void => {
    email.value = '';
    isAdmin.value = false;
    requiresApproval.value = false;
    sentTo.value = null;
};

watch(open, (isOpen) => {
    if (isOpen) {
        resetForm();
    }
});

const onSuccess = async (): Promise<void> => {
    sentTo.value = email.value.trim();
    await nextTick();
    doneButton.value?.$el.focus();
};

const inviteAnother = async (): Promise<void> => {
    resetForm();
    await nextTick();
    emailInput.value?.$el.focus();
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="invite-member-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('settings.members.invite.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ $t('settings.members.invite.description') }}
                </DialogDescription>
            </DialogHeader>
            <div v-if="sentTo !== null" class="grid gap-4">
                <p
                    class="flex items-start gap-2 rounded-xl bg-success-subtle p-4 text-sm text-foreground"
                    role="status"
                    data-testid="invite-member-sent"
                >
                    <IconCircleCheck
                        class="mt-0.5 size-4 shrink-0 text-success"
                        aria-hidden="true"
                    />
                    <TransSlots
                        class="min-w-0 break-words"
                        :message="$t('settings.members.invite.sent')"
                    >
                        <template #email>
                            <strong class="font-emphasis">{{ sentTo }}</strong>
                        </template>
                    </TransSlots>
                </p>

                <DialogFooter>
                    <Button
                        variant="outline"
                        type="button"
                        data-testid="invite-member-another"
                        @click="inviteAnother"
                    >
                        {{ $t('settings.members.invite.another') }}
                    </Button>
                    <Button
                        ref="doneButton"
                        type="button"
                        data-testid="invite-member-done"
                        @click="closeDialog"
                    >
                        {{ $t('settings.members.invite.done') }}
                    </Button>
                </DialogFooter>
            </div>
            <Form
                v-else
                v-bind="storeInvite.form()"
                class="space-y-4"
                v-slot="{ errors, processing }"
                @success="onSuccess"
            >
                <div class="grid gap-2">
                    <Label for="invite-email">{{
                        $t('settings.members.invite.email')
                    }}</Label>
                    <Input
                        id="invite-email"
                        ref="emailInput"
                        v-model="email"
                        name="email"
                        type="email"
                        data-testid="invite-email"
                        :placeholder="
                            $t('settings.members.invite.email_placeholder')
                        "
                    />
                    <InputError :message="errors.email" />
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
                        data-testid="invite-member-cancel"
                        @click="closeDialog"
                    >
                        {{ $t('settings.members.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="processing"
                        data-testid="invite-member-submit"
                    >
                        {{ $t('settings.members.invite.submit') }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
