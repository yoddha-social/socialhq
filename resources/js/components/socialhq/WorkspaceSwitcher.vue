<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    IconCheck,
    IconLayoutList,
    IconPlus,
    IconSelector,
    IconSettings,
    IconUserPlus,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { usePendingAccessRequests } from '@/components/socialhq/signup';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import WorkspaceUpgradeDialog from '@/components/workspaces/WorkspaceUpgradeDialog.vue';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { useWorkspaceLimit } from '@/composables/useWorkspaceLimit';
import { settings as workspaceSettings } from '@/routes/app/workspace';
import {
    switchMethod,
    index as workspacesIndex,
} from '@/routes/app/workspaces';
import { index as accessRequestsIndex } from '@/routes/socialhq/access-requests';

// Social HQ: the workspace (brand) switcher at the top of the sidebar.
// Upstream keeps it in a submenu of the user menu, where it is hard to find.

interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
}

const page = usePage();
const currentWorkspace = computed<Workspace | null>(
    () => page.props.auth.currentWorkspace as Workspace | null,
);
const workspaces = computed<Workspace[]>(
    () => (page.props.auth.workspaces as Workspace[] | undefined) ?? [],
);

const { canCreateWorkspace, canManageWorkspace } = useWorkspaceAbilities();
const { createOrUpgrade } = useWorkspaceLimit(() => workspaces.value.length);

const upgradeDialogOpen = ref(false);
const pendingAccessRequests = usePendingAccessRequests();

const switchWorkspace = (workspaceId: string): void => {
    if (workspaceId === currentWorkspace.value?.id) {
        return;
    }

    router.post(switchMethod.url(workspaceId), {}, { preserveScroll: true });
};

const createWorkspace = (): void => {
    createOrUpgrade(() => {
        upgradeDialogOpen.value = true;
    });
};
</script>

<template>
    <div
        v-if="workspaces.length > 0"
        class="px-4 pt-3 group-data-[collapsible=icon]:px-2.5"
    >
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="flex h-11 w-full cursor-pointer items-center gap-2.5 rounded-lg border border-sidebar-border px-2 text-start outline-hidden transition-control group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:p-0 hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring data-[state=open]:bg-sidebar-accent"
                    :aria-label="$t('socialhq.workspace_switcher.label')"
                    data-testid="socialhq-workspace-switcher"
                >
                    <Avatar
                        :src="currentWorkspace?.logo_url ?? null"
                        :name="currentWorkspace?.name ?? ''"
                        class="size-7 shrink-0 rounded-md border border-border"
                        fallback-class="text-[11px] bg-primary-subtle text-primary-text font-bold"
                    />
                    <span
                        class="grid min-w-0 flex-1 group-data-[collapsible=icon]:hidden"
                    >
                        <span
                            class="text-[11px] leading-4 text-muted-foreground"
                        >
                            {{ $t('socialhq.workspace_switcher.label') }}
                        </span>
                        <span
                            class="truncate text-sm leading-5 font-medium text-sidebar-foreground"
                            data-testid="socialhq-workspace-switcher-name"
                        >
                            {{
                                currentWorkspace?.name ??
                                $t('sidebar.select_workspace')
                            }}
                        </span>
                    </span>
                    <IconSelector
                        class="size-4 shrink-0 text-muted-foreground group-data-[collapsible=icon]:hidden"
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent class="w-64" align="start" :side-offset="4">
                <DropdownMenuLabel
                    class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
                >
                    {{ $t('sidebar.workspaces') }}
                </DropdownMenuLabel>
                <DropdownMenuGroup class="max-h-72 overflow-y-auto">
                    <DropdownMenuItem
                        v-for="workspace in workspaces"
                        :key="workspace.id"
                        class="gap-2"
                        :data-testid="`socialhq-workspace-${workspace.id}`"
                        @select="switchWorkspace(workspace.id)"
                    >
                        <Avatar
                            :src="workspace.logo_url"
                            :name="workspace.name"
                            class="size-6 shrink-0 rounded-md border border-border"
                            fallback-class="text-[10px] bg-primary-subtle text-primary-text font-bold"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            workspace.name
                        }}</span>
                        <IconCheck
                            v-if="workspace.id === currentWorkspace?.id"
                            class="size-4 shrink-0 text-foreground"
                            stroke-width="2.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem :as-child="true">
                    <Link
                        class="block w-full cursor-pointer"
                        :href="workspacesIndex.url()"
                        data-testid="socialhq-workspace-all"
                    >
                        <IconLayoutList class="size-4" />
                        {{ $t('socialhq.workspace_switcher.all') }}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="currentWorkspace && canManageWorkspace"
                    :as-child="true"
                >
                    <Link
                        class="block w-full cursor-pointer"
                        :href="workspaceSettings.url()"
                        data-testid="socialhq-workspace-settings"
                    >
                        <IconSettings class="size-4" />
                        {{ $t('socialhq.workspace_switcher.settings') }}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="pendingAccessRequests > 0"
                    :as-child="true"
                >
                    <Link
                        class="flex w-full cursor-pointer items-center gap-2"
                        :href="accessRequestsIndex.url()"
                        data-testid="socialhq-access-requests-link"
                    >
                        <IconUserPlus class="size-4" />
                        <span class="flex-1">{{
                            $t('socialhq.signup.requests.menu_item')
                        }}</span>
                        <Badge variant="secondary">{{
                            pendingAccessRequests
                        }}</Badge>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="canCreateWorkspace"
                    data-testid="socialhq-workspace-create"
                    @select="createWorkspace"
                >
                    <IconPlus class="size-4" />
                    {{ $t('sidebar.create_workspace') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <WorkspaceUpgradeDialog v-model:open="upgradeDialogOpen" />
    </div>
</template>
