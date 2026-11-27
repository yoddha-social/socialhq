<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
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
import { store as storeRole } from '@/routes/socialhq/roles';
import type { WorkspacePermission } from '@/types';

const props = defineProps<{
    roles: CompanyRole[];
    total: number;
    nameMax: number;
}>();

const open = defineModel<boolean>('open', { default: false });

const BLANK = 'blank';

// Any role but Admin (which can't change) is a starting point, or nothing at all.
const sources = computed(() => props.roles.filter((role) => !role.fixed));
const from = ref<string>(BLANK);

const form = useForm<{
    name: string;
    description: string | null;
    permissions: WorkspacePermission[];
}>({ name: '', description: null, permissions: [] });

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        from.value =
            sources.value.find((role) => role.key === 'editor')?.id ?? BLANK;
    }
});

const pickSource = (id: string): void => {
    from.value = id;
};

const closeDialog = (): void => {
    open.value = false;
};

const createRole = (): void => {
    form.permissions =
        sources.value.find((role) => role.id === from.value)?.permissions ?? [];
    form.post(storeRole.url(), {
        onSuccess: () => {
            open.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="create-role-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('socialhq_roles.create.title')
                }}</DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq_roles.create.description') }}
                </DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="createRole">
                <div class="grid gap-1.5">
                    <Label for="create-role-name">{{
                        $t('socialhq_roles.editor.name')
                    }}</Label>
                    <Input
                        id="create-role-name"
                        v-model="form.name"
                        :placeholder="
                            $t('socialhq_roles.create.name_placeholder')
                        "
                        data-testid="create-role-name"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            $t('socialhq_roles.create.name_hint', {
                                max: String(nameMax),
                            })
                        }}
                    </p>
                    <InputError
                        :message="
                            form.errors.name ??
                            form.errors.permissions ??
                            form.errors.description
                        "
                    />
                </div>

                <div class="grid gap-1.5">
                    <p class="text-sm font-medium">
                        {{ $t('socialhq_roles.create.start_from') }}
                    </p>
                    <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                        <button
                            v-for="source in [
                                ...sources.map((role) => ({
                                    id: role.id,
                                    name: role.name,
                                    count: role.permissions.length,
                                    testid: role.key ?? role.id,
                                })),
                                {
                                    id: BLANK,
                                    name: $t('socialhq_roles.create.nothing'),
                                    count: 0,
                                    testid: BLANK,
                                },
                            ]"
                            :key="source.id"
                            type="button"
                            class="rounded-lg border px-3 py-2 text-left"
                            :class="
                                from === source.id
                                    ? 'border-primary-text bg-primary-subtle/40'
                                    : 'border-border hover:bg-accent'
                            "
                            :aria-pressed="from === source.id"
                            :data-testid="`create-role-from-${source.testid}`"
                            @click="pickSource(source.id)"
                        >
                            <span
                                class="block truncate text-[13px] font-emphasis"
                                >{{ source.name }}</span
                            >
                            <span class="block text-xs text-muted-foreground">{{
                                $t('socialhq_roles.create.permission_count', {
                                    count: String(source.count),
                                    total: String(total),
                                })
                            }}</span>
                        </button>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="ghost" type="button" @click="closeDialog">
                        {{ $t('settings.members.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="create-role-submit"
                    >
                        {{ $t('socialhq_roles.create.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
