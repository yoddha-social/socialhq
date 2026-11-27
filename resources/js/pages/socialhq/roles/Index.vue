<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    IconCrown,
    IconLock,
    IconPlus,
    IconShield,
    IconShieldPlus,
    IconTable,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import CreateRoleDialog from '@/components/socialhq/roles/CreateRoleDialog.vue';
import RoleBadges from '@/components/socialhq/roles/RoleBadges.vue';
import {
    catalogKeys,
    type RolePerson,
    type RolesPageProps,
} from '@/components/socialhq/roles/roles';
import RolesPageShell from '@/components/socialhq/roles/RolesPageShell.vue';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import {
    compare as compareRoles,
    show as showRole,
} from '@/routes/socialhq/roles';

const props = defineProps<RolesPageProps>();

const total = computed(() => catalogKeys(props.catalog).length);
const createOpen = ref(false);

// Up to three faces per role, each person once (they may have it in several workspaces).
const faces = (people: RolePerson[] = []): RolePerson[] =>
    people
        .filter(
            (person, index) =>
                people.findIndex((other) => other.id === person.id) === index,
        )
        .slice(0, 3);

const openCreateDialog = (): void => {
    createOpen.value = true;
};
</script>

<template>
    <Head :title="$t('socialhq_roles.list.title')" />

    <SettingsLayout>
        <RolesPageShell
            :title="$t('socialhq_roles.list.title')"
            :description="
                company
                    ? $t('socialhq_roles.list.description', { company })
                    : $t('socialhq_roles.list.description_no_company')
            "
        >
            <template #actions>
                <Button variant="outline" as-child>
                    <Link :href="compareRoles()" data-testid="roles-compare">
                        <IconTable class="size-4" />
                        {{ $t('socialhq_roles.list.compare') }}
                    </Link>
                </Button>
                <Button
                    v-if="canManage"
                    data-testid="roles-create"
                    @click="openCreateDialog"
                >
                    <IconPlus class="size-4" />
                    {{ $t('socialhq_roles.list.create') }}
                </Button>
            </template>

            <div
                v-if="!canManage"
                class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2.5 text-[13px] text-muted-foreground"
                data-testid="roles-owner-note"
            >
                <IconLock class="size-4 shrink-0" />
                <span>
                    {{
                        $t('socialhq_roles.list.owner_note', {
                            owner: owner?.name ?? '',
                        })
                    }}
                    {{
                        $t('socialhq_roles.list.workspace_scope', {
                            workspace: workspace.name,
                        })
                    }}
                </span>
            </div>

            <div
                class="overflow-hidden rounded-xl border border-border bg-card"
                data-testid="roles-list"
            >
                <div
                    class="flex items-center gap-4 border-b border-border bg-muted/40 px-4 py-3.5"
                    data-testid="roles-owner-row"
                >
                    <span
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-stone-800 text-amber-100"
                    >
                        <IconCrown class="size-[17px]" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex items-center gap-2 text-sm font-emphasis"
                        >
                            {{ $t('socialhq_roles.badges.owner') }}
                            <Badge variant="secondary" class="h-[22px] px-2">
                                <IconLock />{{
                                    $t('socialhq_roles.badges.fixed')
                                }}
                            </Badge>
                        </p>
                        <p class="text-[13px] text-muted-foreground">
                            {{ $t('socialhq_roles.list.owner_description') }}
                        </p>
                    </div>
                    <span v-if="owner" class="flex">
                        <Avatar
                            :src="owner.photo_url"
                            :name="owner.name"
                            class="size-7 ring-2 ring-card"
                            fallback-class="bg-sidebar-accent text-[10.5px] text-sidebar-accent-foreground"
                        />
                    </span>
                    <span class="hidden w-[72px] sm:block" />
                </div>

                <Link
                    v-for="role in roles"
                    :key="role.id"
                    :href="showRole(role.id)"
                    class="flex items-center gap-4 border-b border-border px-4 py-3.5 last:border-b-0 hover:bg-muted/40"
                    :data-testid="`roles-row-${role.key ?? role.id}`"
                >
                    <span
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-border text-muted-foreground"
                    >
                        <IconShield v-if="role.built_in" class="size-[17px]" />
                        <IconShieldPlus v-else class="size-[17px]" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex flex-wrap items-center gap-2 text-sm font-emphasis"
                        >
                            {{ role.name }}
                            <RoleBadges :role="role" />
                        </p>
                        <p
                            v-if="role.description"
                            class="text-[13px] text-muted-foreground"
                        >
                            {{ role.description }}
                        </p>
                    </div>
                    <span
                        class="hidden text-[12.5px] whitespace-nowrap text-muted-foreground sm:inline"
                        :data-testid="`roles-row-count-${role.key ?? role.id}`"
                    >
                        {{
                            $t('socialhq_roles.list.permission_count', {
                                count: String(role.permissions.length),
                                total: String(total),
                            })
                        }}
                    </span>
                    <span class="flex w-[70px] justify-end -space-x-1.5">
                        <Avatar
                            v-for="person in faces(role.people)"
                            :key="person.id"
                            :src="person.photo_url"
                            :name="person.name"
                            class="size-7 ring-2 ring-card"
                            fallback-class="bg-sidebar-accent text-[10.5px] text-sidebar-accent-foreground"
                        />
                        <span
                            v-if="!role.people?.length"
                            class="text-[12.5px] whitespace-nowrap text-muted-foreground"
                            >{{ $t('socialhq_roles.list.no_one') }}</span
                        >
                    </span>
                    <span
                        class="hidden w-[72px] text-right text-[13px] font-medium text-primary-text sm:block"
                    >
                        {{
                            canManage && !role.fixed
                                ? $t('socialhq_roles.list.edit')
                                : $t('socialhq_roles.list.view')
                        }}
                        →
                    </span>
                </Link>
            </div>

            <p class="text-[12.5px] text-muted-foreground">
                {{ $t('socialhq_roles.list.agents_note') }}
            </p>

            <CreateRoleDialog
                v-if="canManage"
                v-model:open="createOpen"
                :roles="roles"
                :total="total"
                :name-max="limits.name"
            />
        </RolesPageShell>
    </SettingsLayout>
</template>
