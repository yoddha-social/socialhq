<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconMinus, IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import {
    type CatalogGroup,
    type CompanyRole,
    permissionDiff,
    permissionLabelKey,
} from '@/components/socialhq/roles/roles';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { update as updateMember } from '@/routes/app/members';
import type { WorkspacePermission } from '@/types';

const props = defineProps<{
    member: {
        id: string;
        name: string;
        permissions: WorkspacePermission[];
    } | null;
    fromLabel: string;
    to: CompanyRole | null;
    catalog: CatalogGroup[];
    workspaceName: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const processing = ref(false);
const error = ref<string | null>(null);

const diff = computed(() =>
    permissionDiff(
        props.catalog,
        props.member?.permissions ?? [],
        props.to?.permissions ?? [],
    ),
);
const losesPublishing = computed(() =>
    diff.value.losses.includes('posts.publish'),
);
const losesApiKeys = computed(() =>
    diff.value.losses.includes('api_keys.manage'),
);
const firstName = computed(
    () => props.member?.name.split(' ')[0] ?? props.member?.name ?? '',
);

const closeDialog = (): void => {
    open.value = false;
};

const confirmChange = (): void => {
    if (!props.member || !props.to) {
        return;
    }

    processing.value = true;
    error.value = null;

    router.put(
        updateMember.url(props.member.id),
        { role_id: props.to.id },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
            },
            onError: (errors) => {
                error.value =
                    errors.role_id ?? Object.values(errors)[0] ?? null;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="change-role-dialog">
            <DialogHeader v-if="member && to">
                <DialogTitle>{{
                    $t('socialhq_roles.change.title', {
                        name: firstName,
                        role: to.name,
                    })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t('socialhq_roles.change.description', {
                            from: fromLabel,
                            to: to.name,
                            workspace: workspaceName,
                        })
                    }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="member && to" class="flex flex-col gap-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div
                        v-for="column in [
                            {
                                key: 'gains',
                                items: diff.gains,
                                title: 'socialhq_roles.change.can_now',
                            },
                            {
                                key: 'losses',
                                items: diff.losses,
                                title: 'socialhq_roles.change.can_no_longer',
                            },
                        ]"
                        :key="column.key"
                        class="rounded-xl border border-border p-3"
                        :data-testid="`change-role-${column.key}`"
                    >
                        <p
                            class="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ $t(column.title) }}
                        </p>
                        <ul class="flex flex-col gap-1.5">
                            <li
                                v-for="key in column.items"
                                :key="key"
                                class="flex gap-2 text-[13px]"
                            >
                                <IconPlus
                                    v-if="column.key === 'gains'"
                                    class="mt-0.5 size-3.5 shrink-0 text-success-text"
                                />
                                <IconMinus
                                    v-else
                                    class="mt-0.5 size-3.5 shrink-0 text-destructive-text"
                                />
                                {{ $t(permissionLabelKey(key)) }}
                            </li>
                            <li
                                v-if="column.items.length === 0"
                                class="text-[13px] text-muted-foreground"
                            >
                                {{ $t('socialhq_roles.change.nothing') }}
                            </li>
                        </ul>
                    </div>
                </div>

                <p
                    v-if="losesPublishing"
                    class="flex gap-2 rounded-lg bg-amber-50 p-3 text-[13px] text-amber-800 dark:bg-amber-950 dark:text-amber-300"
                    data-testid="change-role-approval-warning"
                >
                    <IconAlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ $t('socialhq_roles.change.approval_warning') }}
                </p>
                <p
                    v-if="losesApiKeys"
                    class="text-[12.5px] text-muted-foreground"
                    data-testid="change-role-api-keys"
                >
                    {{ $t('socialhq_roles.change.api_keys') }}
                </p>
                <InputError :message="error ?? undefined" />
            </div>

            <DialogFooter>
                <Button
                    variant="ghost"
                    type="button"
                    data-testid="change-role-cancel"
                    @click="closeDialog"
                >
                    {{ $t('settings.members.cancel') }}
                </Button>
                <Button
                    type="button"
                    :loading="processing"
                    data-testid="change-role-submit"
                    @click="confirmChange"
                >
                    {{ $t('socialhq_roles.change.submit') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
