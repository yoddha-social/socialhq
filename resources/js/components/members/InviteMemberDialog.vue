<script setup lang="ts">
import { Form, Link, useHttp } from '@inertiajs/vue3';
import { IconCircleCheck } from '@tabler/icons-vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import RoleChoiceList from '@/components/socialhq/roles/RoleChoiceList.vue';
import type { CompanyRole } from '@/components/socialhq/roles/roles';
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
import { Skeleton } from '@/components/ui/skeleton';
import { store as storeInvite } from '@/routes/app/invites';
import {
    assignable as assignableRoles,
    compare as compareRoles,
} from '@/routes/socialhq/roles';

// Social HQ: the invite joins with a role. The members page passes the
// company's roles; elsewhere (sidebar, command palette, publish page) the
// dialog loads them when it opens.
const props = defineProps<{ roles?: CompanyRole[] }>();

const open = defineModel<boolean>('open', { default: false });

const loadedRoles = ref<CompanyRole[] | null>(null);
const loadFailed = ref(false);
const rolesHttp = useHttp<Record<string, never>, { roles: CompanyRole[] }>({});
const roles = computed(() => props.roles ?? loadedRoles.value);
const roleId = ref<string | null>(null);
const email = ref('');

// Social HQ: after sending, the dialog stays open and says who got the
// invite, with "Invite another" and "Done" (the toast was easy to miss).
const sentTo = ref<string | null>(null);
const doneButton = useTemplateRef<{ $el: HTMLElement }>('doneButton');
const emailInput = useTemplateRef<{ $el: HTMLElement }>('emailInput');

const isAvailable = (role: CompanyRole): boolean => role.assignable === true;

// Editor by default, else the first role the inviter may give.
const pickDefault = (): void => {
    const list = roles.value ?? [];
    const editor = list.find(
        (role) => role.key === 'editor' && isAvailable(role),
    );

    roleId.value = (editor ?? list.find(isAvailable))?.id ?? null;
};

const resetForm = (): void => {
    email.value = '';
    sentTo.value = null;
    pickDefault();
};

const loadRoles = async (): Promise<void> => {
    loadFailed.value = false;

    try {
        loadedRoles.value = (await rolesHttp.get(assignableRoles.url())).roles;
    } catch {
        loadFailed.value = true;
    }
};

watch(
    open,
    async (isOpen) => {
        if (!isOpen) {
            return;
        }

        resetForm();

        if (props.roles === undefined) {
            await loadRoles();
            pickDefault();
        }
    },
    { immediate: true },
);

const closeDialog = (): void => {
    open.value = false;
};

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

                <div class="grid gap-2">
                    <Label id="invite-role-label">{{
                        $t('socialhq_roles.invite.role')
                    }}</Label>
                    <RoleChoiceList
                        v-if="roles"
                        v-model="roleId"
                        :roles="roles"
                        :available="isAvailable"
                        labelled-by="invite-role-label"
                    />
                    <p
                        v-else-if="loadFailed"
                        class="text-sm text-destructive-text"
                        data-testid="invite-roles-failed"
                    >
                        {{ $t('socialhq_roles.invite.load_failed') }}
                    </p>
                    <div v-else class="flex flex-col gap-1.5">
                        <Skeleton
                            v-for="index in 4"
                            :key="index"
                            class="h-14"
                        />
                    </div>
                    <input type="hidden" name="role_id" :value="roleId ?? ''" />
                    <InputError :message="errors.role_id" />
                </div>

                <DialogFooter class="items-center sm:justify-between">
                    <Link
                        :href="compareRoles()"
                        class="text-[12.5px] font-medium text-primary-text hover:underline"
                        data-testid="invite-compare-roles"
                    >
                        {{ $t('socialhq_roles.invite.compare') }}
                    </Link>
                    <span class="flex gap-2">
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
                            :disabled="!roleId"
                            data-testid="invite-member-submit"
                        >
                            {{ $t('settings.members.invite.submit') }}
                        </Button>
                    </span>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
