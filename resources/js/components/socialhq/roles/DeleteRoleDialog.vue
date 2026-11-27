<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

import InputError from '@/components/InputError.vue';
import type { CompanyRole } from '@/components/socialhq/roles/roles';
import { Avatar } from '@/components/ui/avatar';
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
import { NativeSelect } from '@/components/ui/native-select';
import { destroy as destroyRole } from '@/routes/socialhq/roles';

const props = defineProps<{
    role: CompanyRole;
    roles: CompanyRole[];
}>();

const open = defineModel<boolean>('open', { default: false });

const people = computed(() => props.role.people ?? []);
const invites = computed(() => props.role.pending_invites ?? 0);
const inUse = computed(() => people.value.length > 0 || invites.value > 0);
const others = computed(() =>
    props.roles.filter((role) => role.id !== props.role.id),
);
const moveTo = ref<string>('');
const processing = ref(false);
const error = ref<string | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        error.value = null;
        moveTo.value =
            others.value.find((role) => role.key === 'editor')?.id ??
            others.value[0]?.id ??
            '';
    }
});

const closeDialog = (): void => {
    open.value = false;
};

const deleteRole = (): void => {
    processing.value = true;
    error.value = null;

    router.delete(destroyRole.url(props.role.id), {
        data: inUse.value ? { move_to: moveTo.value } : {},
        onError: (errors) => {
            error.value =
                errors.move_to ??
                errors.role ??
                Object.values(errors)[0] ??
                null;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg" data-testid="delete-role-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    $t('socialhq_roles.delete.title', { name: role.name })
                }}</DialogTitle>
                <DialogDescription>
                    <template v-if="people.length">{{
                        $tChoice(
                            'socialhq_roles.delete.people',
                            people.length,
                            {
                                count: String(people.length),
                            },
                        )
                    }}</template>
                    <template v-else-if="invites">{{
                        $tChoice('socialhq_roles.delete.invites', invites, {
                            count: String(invites),
                        })
                    }}</template>
                    <template v-else>{{
                        $t('socialhq_roles.delete.nobody')
                    }}</template>
                </DialogDescription>
            </DialogHeader>

            <div v-if="inUse" class="flex flex-col gap-4">
                <ul
                    v-if="people.length"
                    class="flex max-h-48 flex-col gap-1.5 overflow-auto"
                >
                    <li
                        v-for="person in people"
                        :key="`${person.id}-${person.workspace_id}`"
                        class="flex items-center gap-2.5 text-[13px]"
                    >
                        <Avatar
                            :src="person.photo_url"
                            :name="person.name"
                            class="size-7"
                            fallback-class="bg-sidebar-accent text-[10.5px] text-sidebar-accent-foreground"
                        />
                        <span class="flex-1 truncate">{{ person.name }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            person.workspace
                        }}</span>
                    </li>
                </ul>
                <div
                    class="grid gap-1.5 [&_[data-slot=native-select-wrapper]]:w-full"
                >
                    <Label for="delete-role-move-to">{{
                        $t('socialhq_roles.delete.move_to')
                    }}</Label>
                    <NativeSelect
                        id="delete-role-move-to"
                        class="h-10"
                        v-model="moveTo"
                        data-testid="delete-role-move-to"
                    >
                        <option
                            v-for="other in others"
                            :key="other.id"
                            :value="other.id"
                        >
                            {{ other.name }}
                        </option>
                    </NativeSelect>
                </div>
            </div>
            <InputError :message="error ?? undefined" />

            <DialogFooter>
                <Button variant="ghost" type="button" @click="closeDialog">
                    {{ $t('settings.members.cancel') }}
                </Button>
                <Button
                    variant="destructive"
                    type="button"
                    :loading="processing"
                    data-testid="delete-role-submit"
                    @click="deleteRole"
                >
                    {{ $t('socialhq_roles.delete.submit') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
