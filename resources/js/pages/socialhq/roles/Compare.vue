<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconCheck, IconLock } from '@tabler/icons-vue';
import { computed } from 'vue';

import {
    peopleCount,
    permissionHintKey,
    permissionLabelKey,
    type RolesPageProps,
} from '@/components/socialhq/roles/roles';
import RolesPageShell from '@/components/socialhq/roles/RolesPageShell.vue';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { index as rolesIndex, show as showRole } from '@/routes/socialhq/roles';

const props = defineProps<RolesPageProps>();

const ownerOnly = ['roles', 'company_ai', 'workspaces'] as const;
const columns = computed(() => props.roles.length + 2);
</script>

<template>
    <Head :title="$t('socialhq_roles.compare.title')" />

    <SettingsLayout>
        <RolesPageShell
            width="wide"
            :title="$t('socialhq_roles.compare.title')"
            :description="$t('socialhq_roles.compare.description')"
            :crumbs="[
                {
                    label: $t('socialhq_roles.list.title'),
                    href: rolesIndex.url(),
                },
                { label: $t('socialhq_roles.compare.title') },
            ]"
        >
            <div
                class="overflow-auto rounded-xl border border-border bg-card"
                data-testid="roles-matrix"
            >
                <table class="w-full min-w-[820px] border-collapse text-[13px]">
                    <thead>
                        <tr class="border-b border-border">
                            <th
                                class="sticky left-0 bg-card px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                {{ $t('socialhq_roles.compare.permission') }}
                            </th>
                            <th class="px-2 py-3 text-center font-emphasis">
                                {{ $t('socialhq_roles.badges.owner') }}
                            </th>
                            <th
                                v-for="role in roles"
                                :key="role.id"
                                class="px-2 py-3 text-center"
                            >
                                <Link
                                    :href="showRole(role.id)"
                                    class="font-emphasis hover:text-primary-text"
                                    :data-testid="`roles-matrix-role-${role.key ?? role.id}`"
                                    >{{ role.name }}</Link
                                >
                                <span
                                    class="block text-[11.5px] font-normal text-muted-foreground"
                                    >{{
                                        $tChoice(
                                            'socialhq_roles.compare.people',
                                            peopleCount(role.people),
                                            {
                                                count: String(
                                                    peopleCount(role.people),
                                                ),
                                            },
                                        )
                                    }}</span
                                >
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in catalog" :key="group.key">
                            <tr class="bg-muted/50">
                                <td
                                    :colspan="columns"
                                    class="px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    {{
                                        $t(`socialhq_roles.groups.${group.key}`)
                                    }}
                                </td>
                            </tr>
                            <tr
                                v-for="item in group.permissions"
                                :key="item.key"
                                class="border-b border-border last:border-b-0"
                                :data-testid="`roles-matrix-row-${item.key.replace('.', '-')}`"
                            >
                                <td
                                    class="sticky left-0 bg-card px-4 py-2.5"
                                    :title="$t(permissionHintKey(item.key))"
                                >
                                    {{ $t(permissionLabelKey(item.key)) }}
                                </td>
                                <td class="text-center">
                                    <IconCheck
                                        class="inline size-4 text-success-text"
                                    />
                                </td>
                                <td
                                    v-for="role in roles"
                                    :key="role.id"
                                    class="text-center"
                                    :data-testid="`roles-matrix-cell-${item.key.replace('.', '-')}-${role.key ?? role.id}`"
                                    :data-granted="
                                        role.permissions.includes(item.key)
                                    "
                                >
                                    <IconCheck
                                        v-if="
                                            role.permissions.includes(item.key)
                                        "
                                        class="inline size-4 text-success-text"
                                    />
                                    <span v-else class="text-border">—</span>
                                </td>
                            </tr>
                        </template>
                        <tr class="bg-muted/50">
                            <td
                                :colspan="columns"
                                class="px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                {{
                                    $t('socialhq_roles.editor.owner_only.title')
                                }}
                            </td>
                        </tr>
                        <tr
                            v-for="item in ownerOnly"
                            :key="item"
                            class="border-b border-border last:border-b-0"
                        >
                            <td
                                class="sticky left-0 bg-card px-4 py-2.5 text-muted-foreground"
                            >
                                {{
                                    $t(
                                        `socialhq_roles.editor.owner_only.${item}.label`,
                                    )
                                }}
                            </td>
                            <td class="text-center">
                                <IconCheck
                                    class="inline size-4 text-success-text"
                                />
                            </td>
                            <td
                                v-for="role in roles"
                                :key="role.id"
                                class="text-center"
                            >
                                <IconLock
                                    class="inline size-3.5 text-muted-foreground/50"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </RolesPageShell>
    </SettingsLayout>
</template>
