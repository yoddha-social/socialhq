<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    IconBook,
    IconBuilding,
    IconCalendarEvent,
    IconCalendarStats,
    IconCheck,
    IconCode,
    IconCopy,
    IconCrown,
    IconInfoCircle,
    IconLink,
    IconLock,
    IconTrash,
    IconX,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import InputError from '@/components/InputError.vue';
import DeleteRoleDialog from '@/components/socialhq/roles/DeleteRoleDialog.vue';
import {
    type CatalogPermission,
    peopleCount,
    permissionHintKey,
    permissionLabelKey,
    type RolesPageProps,
    roleSummary,
    sameSet,
    togglePermission,
} from '@/components/socialhq/roles/roles';
import RolesPageShell from '@/components/socialhq/roles/RolesPageShell.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import {
    duplicate as duplicateRole,
    index as rolesIndex,
    update as updateRole,
} from '@/routes/socialhq/roles';
import type { WorkspacePermission } from '@/types';

const props = defineProps<RolesPageProps & { roleId: string }>();

const role = computed(
    () =>
        props.roles.find((item) => item.id === props.roleId) ?? props.roles[0],
);
const editable = computed(() => props.canManage && !role.value.fixed);
const people = computed(() => role.value.people ?? []);

const groupIcons: Record<string, unknown> = {
    posts: IconCalendarEvent,
    plan: IconCalendarStats,
    content: IconBook,
    workspace: IconBuilding,
    integrations: IconCode,
};
const ownerOnly = ['roles', 'company_ai', 'workspaces'] as const;

// The draft: what the switches and fields show until saved.
const permissions = ref<WorkspacePermission[]>([]);
const name = ref('');
const description = ref('');
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const note = ref<{
    key: WorkspacePermission;
    also: WorkspacePermission[];
    on: boolean;
} | null>(null);

const resetDraft = (): void => {
    permissions.value = [...role.value.permissions];
    name.value = role.value.name;
    description.value = role.value.description ?? '';
    errors.value = {};
    note.value = null;
};

watch(role, resetDraft, { immediate: true });

const dirty = computed(
    () =>
        !sameSet(permissions.value, role.value.permissions) ||
        (!role.value.built_in &&
            (name.value !== role.value.name ||
                description.value !== (role.value.description ?? ''))),
);
const changedFromDefault = computed(
    () =>
        role.value.defaults !== null &&
        !sameSet(permissions.value, role.value.defaults),
);
const summary = computed(() => roleSummary(permissions.value));

const has = (key: WorkspacePermission): boolean =>
    permissions.value.includes(key);

const labels = (keys: WorkspacePermission[]): string =>
    keys.map((key) => trans(permissionLabelKey(key))).join(', ');

const toggle = (key: WorkspacePermission): void => {
    const result = togglePermission(props.catalog, permissions.value, key);

    permissions.value = result.permissions;
    note.value = result.also.length
        ? { key, also: result.also, on: result.on }
        : null;
};

const resetToDefault = (): void => {
    if (role.value.defaults) {
        permissions.value = [...role.value.defaults];
        note.value = null;
    }
};

const save = (): void => {
    saving.value = true;
    errors.value = {};

    router.put(
        updateRole.url(role.value.id),
        role.value.built_in
            ? { permissions: permissions.value }
            : {
                  name: name.value,
                  description: description.value,
                  permissions: permissions.value,
              },
        {
            preserveScroll: true,
            onError: (failed) => {
                errors.value = failed;
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};

const duplicate = (): void => {
    router.post(
        duplicateRole.url(role.value.id),
        {},
        {
            onError: (failed) => {
                toast.error(Object.values(failed)[0] ?? '');
            },
        },
    );
};

const deleteOpen = ref(false);
const openDeleteDialog = (): void => {
    deleteOpen.value = true;
};

const needsText = (item: CatalogPermission): string =>
    trans('socialhq_roles.editor.needs', { permissions: labels(item.needs) });
</script>

<template>
    <Head :title="role.name" />

    <SettingsLayout>
        <RolesPageShell
            width="wide"
            :title="role.built_in ? role.name : name || role.name"
            :description="role.description"
            :crumbs="[
                {
                    label: $t('socialhq_roles.list.title'),
                    href: rolesIndex.url(),
                },
                { label: role.name },
            ]"
        >
            <template v-if="editable" #actions>
                <Button
                    variant="outline"
                    data-testid="role-duplicate"
                    @click="duplicate"
                >
                    <IconCopy class="size-4" />
                    {{ $t('socialhq_roles.editor.duplicate') }}
                </Button>
                <Button
                    v-if="!role.built_in"
                    variant="ghost"
                    class="text-destructive-text hover:bg-destructive/10"
                    data-testid="role-delete"
                    @click="openDeleteDialog"
                >
                    <IconTrash class="size-4" />
                    {{ $t('socialhq_roles.editor.delete') }}
                </Button>
            </template>

            <div
                v-if="!editable"
                class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2.5 text-[13px] text-muted-foreground"
                data-testid="role-locked"
            >
                <IconLock class="size-4 shrink-0" />
                {{
                    role.fixed
                        ? $t('socialhq_roles.errors.fixed')
                        : $t('socialhq_roles.errors.owner_only')
                }}
            </div>

            <div class="grid items-start gap-6 pb-16 lg:grid-cols-[1fr_320px]">
                <div class="flex flex-col gap-5" data-testid="role-editor">
                    <section
                        v-if="editable && !role.built_in"
                        class="rounded-xl border border-border bg-card p-4"
                    >
                        <div class="grid gap-3 sm:grid-cols-[220px_1fr]">
                            <div class="grid gap-1.5">
                                <Label for="role-name">{{
                                    $t('socialhq_roles.editor.name')
                                }}</Label>
                                <Input
                                    id="role-name"
                                    v-model="name"
                                    data-testid="role-name"
                                />
                                <InputError :message="errors.name" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="role-description">{{
                                    $t('socialhq_roles.editor.description')
                                }}</Label>
                                <Input
                                    id="role-description"
                                    v-model="description"
                                    data-testid="role-description"
                                />
                                <InputError :message="errors.description" />
                            </div>
                        </div>
                    </section>

                    <section
                        v-for="group in catalog"
                        :key="group.key"
                        class="overflow-hidden rounded-xl border border-border bg-card"
                        :data-testid="`role-group-${group.key}`"
                    >
                        <div
                            class="flex items-center gap-2 border-b border-border bg-muted/40 px-4 py-2.5"
                        >
                            <component
                                :is="groupIcons[group.key]"
                                class="size-4 text-muted-foreground"
                            />
                            <span class="text-[13px] font-emphasis">{{
                                $t(`socialhq_roles.groups.${group.key}`)
                            }}</span>
                            <span class="ms-auto text-xs text-muted-foreground">
                                {{
                                    $t('socialhq_roles.editor.group_count', {
                                        count: String(
                                            group.permissions.filter((item) =>
                                                has(item.key),
                                            ).length,
                                        ),
                                        total: String(group.permissions.length),
                                    })
                                }}
                            </span>
                        </div>
                        <div class="divide-y divide-border">
                            <div
                                v-for="item in group.permissions"
                                :key="item.key"
                                class="flex items-start justify-between gap-6 px-4 py-3"
                                :class="{
                                    'role-flash': note?.also.includes(item.key),
                                }"
                                :data-testid="`role-permission-${item.key.replace('.', '-')}`"
                            >
                                <div class="min-w-0">
                                    <label
                                        :for="`role-switch-${item.key}`"
                                        class="text-sm font-medium"
                                        >{{
                                            $t(permissionLabelKey(item.key))
                                        }}</label
                                    >
                                    <p
                                        class="text-[13px] leading-snug text-muted-foreground"
                                    >
                                        {{ $t(permissionHintKey(item.key)) }}
                                    </p>
                                    <p
                                        v-if="item.needs.length"
                                        class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                                    >
                                        <IconLink class="size-3" />
                                        {{ needsText(item) }}
                                    </p>
                                    <p
                                        v-if="note?.key === item.key"
                                        class="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-primary-subtle px-2 py-1 text-[12.5px] text-primary-text"
                                        data-testid="role-dependency-note"
                                    >
                                        <IconInfoCircle
                                            class="size-3.5 shrink-0"
                                        />
                                        {{
                                            $tChoice(
                                                note.on
                                                    ? 'socialhq_roles.editor.also_on'
                                                    : 'socialhq_roles.editor.also_off',
                                                note.also.length,
                                                {
                                                    permissions: labels(
                                                        note.also,
                                                    ),
                                                    permission: $t(
                                                        permissionLabelKey(
                                                            item.key,
                                                        ),
                                                    ),
                                                },
                                            )
                                        }}
                                    </p>
                                </div>
                                <Switch
                                    :id="`role-switch-${item.key}`"
                                    class="mt-0.5 shrink-0"
                                    :model-value="has(item.key)"
                                    :disabled="!editable"
                                    :data-testid="`role-switch-${item.key.replace('.', '-')}`"
                                    @update:model-value="toggle(item.key)"
                                />
                            </div>
                        </div>
                    </section>

                    <InputError :message="errors.permissions" />

                    <section
                        class="overflow-hidden rounded-xl border border-dashed border-border bg-card"
                        data-testid="role-owner-only"
                    >
                        <div
                            class="flex items-center gap-2 border-b border-border px-4 py-2.5"
                        >
                            <IconCrown class="size-4 text-muted-foreground" />
                            <span class="text-[13px] font-emphasis">{{
                                $t('socialhq_roles.editor.owner_only.title')
                            }}</span>
                            <span
                                class="ms-auto text-xs text-muted-foreground"
                                >{{
                                    $t('socialhq_roles.editor.owner_only.hint')
                                }}</span
                            >
                        </div>
                        <div class="divide-y divide-border">
                            <div
                                v-for="item in ownerOnly"
                                :key="item"
                                class="flex items-center justify-between gap-6 px-4 py-3"
                            >
                                <div>
                                    <p
                                        class="text-sm font-medium text-muted-foreground"
                                    >
                                        {{
                                            $t(
                                                `socialhq_roles.editor.owner_only.${item}.label`,
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="text-[13px] text-muted-foreground"
                                    >
                                        {{
                                            $t(
                                                `socialhq_roles.editor.owner_only.${item}.hint`,
                                            )
                                        }}
                                    </p>
                                </div>
                                <IconLock
                                    class="size-4 text-muted-foreground"
                                />
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="flex flex-col gap-4 lg:sticky lg:top-6">
                    <section
                        class="rounded-xl border border-border bg-card p-4"
                        data-testid="role-summary"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ $t('socialhq_roles.editor.summary.title') }}
                        </p>
                        <ul class="mt-2 flex flex-col gap-1.5">
                            <li
                                v-for="key in summary.can"
                                :key="key"
                                class="flex gap-2 text-[13px]"
                            >
                                <IconCheck
                                    class="mt-0.5 size-3.5 shrink-0 text-success-text"
                                />
                                {{ $t(key) }}
                            </li>
                        </ul>
                        <ul
                            v-if="summary.cannot.length"
                            class="mt-2 flex flex-col gap-1.5 border-t border-border pt-2"
                        >
                            <li
                                v-for="key in summary.cannot"
                                :key="key"
                                class="flex gap-2 text-[13px] text-muted-foreground"
                            >
                                <IconX class="mt-0.5 size-3.5 shrink-0" />
                                {{ $t(key) }}
                            </li>
                        </ul>
                    </section>

                    <section
                        class="rounded-xl border border-border bg-card p-4"
                        data-testid="role-people"
                    >
                        <div class="flex items-center justify-between">
                            <p
                                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                {{ $t('socialhq_roles.editor.people.title') }}
                            </p>
                            <span class="text-xs text-muted-foreground">{{
                                people.length
                            }}</span>
                        </div>
                        <ul
                            v-if="people.length"
                            class="mt-2 flex flex-col gap-2"
                        >
                            <li
                                v-for="person in people"
                                :key="`${person.id}-${person.workspace_id}`"
                                class="flex items-center gap-2.5"
                            >
                                <Avatar
                                    :src="person.photo_url"
                                    :name="person.name"
                                    class="size-7"
                                    fallback-class="bg-sidebar-accent text-[10.5px] text-sidebar-accent-foreground"
                                />
                                <span
                                    class="min-w-0 flex-1 truncate text-[13px]"
                                    >{{ person.name }}</span
                                >
                                <span
                                    class="truncate text-xs text-muted-foreground"
                                    >{{ person.workspace }}</span
                                >
                            </li>
                        </ul>
                        <p
                            v-else
                            class="mt-2 text-[13px] text-muted-foreground"
                        >
                            {{ $t('socialhq_roles.editor.people.empty') }}
                        </p>
                    </section>

                    <section
                        v-if="role.defaults"
                        class="rounded-xl border border-border bg-card p-4"
                        data-testid="role-reset"
                    >
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ $t('socialhq_roles.editor.reset.title') }}
                        </p>
                        <p class="mt-1.5 text-[13px] text-muted-foreground">
                            {{
                                changedFromDefault
                                    ? $t('socialhq_roles.editor.reset.changed')
                                    : $t('socialhq_roles.editor.reset.default')
                            }}
                        </p>
                        <button
                            v-if="editable && changedFromDefault"
                            type="button"
                            class="mt-2 text-[13px] font-medium text-primary-text hover:underline"
                            data-testid="role-reset-default"
                            @click="resetToDefault"
                        >
                            {{ $t('socialhq_roles.editor.reset.action') }}
                        </button>
                    </section>
                </aside>
            </div>

            <div
                v-if="editable && dirty"
                class="pointer-events-none fixed inset-x-0 bottom-5 z-40 flex justify-center md:ps-60"
                data-testid="role-save-bar"
            >
                <div
                    class="pointer-events-auto flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card px-4 py-2.5 shadow-lg"
                >
                    <span class="text-[13px] text-muted-foreground">{{
                        $tChoice(
                            'socialhq_roles.editor.unsaved',
                            peopleCount(people),
                            {
                                count: String(peopleCount(people)),
                            },
                        )
                    }}</span>
                    <Button
                        variant="outline"
                        size="sm"
                        data-testid="role-discard"
                        @click="resetDraft"
                    >
                        {{ $t('socialhq_roles.editor.discard') }}
                    </Button>
                    <Button
                        size="sm"
                        :loading="saving"
                        data-testid="role-save"
                        @click="save"
                    >
                        {{ $t('socialhq_roles.editor.save') }}
                    </Button>
                </div>
            </div>

            <DeleteRoleDialog
                v-if="editable && !role.built_in"
                v-model:open="deleteOpen"
                :role="role"
                :roles="roles"
            />
        </RolesPageShell>
    </SettingsLayout>
</template>

<style scoped>
.role-flash {
    animation: role-flash 1.2s ease-out;
}

@keyframes role-flash {
    0% {
        background: var(--primary-subtle);
    }

    100% {
        background: transparent;
    }
}
</style>
