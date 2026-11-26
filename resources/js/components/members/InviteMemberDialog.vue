<script setup lang="ts">
import { Form, Link, useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

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

const isAvailable = (role: CompanyRole): boolean => role.assignable === true;

// Editor by default, else the first role the inviter may give.
const pickDefault = (): void => {
    const list = roles.value ?? [];
    const editor = list.find(
        (role) => role.key === 'editor' && isAvailable(role),
    );

    roleId.value = (editor ?? list.find(isAvailable))?.id ?? null;
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

        if (props.roles === undefined) {
            await loadRoles();
        }

        pickDefault();
    },
    { immediate: true },
);

const closeDialog = (): void => {
    open.value = false;
};

const onSuccess = (): void => {
    open.value = false;
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
            <Form
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
