import { router, usePage } from '@inertiajs/vue3';
import { IconSwitchHorizontal } from '@tabler/icons-vue';
import { computed, type ComputedRef } from 'vue';

import { switchMethod } from '@/routes/app/workspaces';
import type { CommandPaletteEntry } from '@/types/command-palette';

interface Workspace {
    id: string;
    name: string;
}

/**
 * Social HQ: "Switch to <workspace>" entries for the command palette, one per
 * workspace other than the current one.
 */
export const useWorkspaceCommandEntries = (): ComputedRef<
    CommandPaletteEntry[]
> => {
    const page = usePage();

    return computed(() => {
        const currentId = (
            page.props.auth?.currentWorkspace as Workspace | null
        )?.id;
        const workspaces =
            (page.props.auth?.workspaces as Workspace[] | undefined) ?? [];

        return workspaces
            .filter((workspace) => workspace.id !== currentId)
            .map((workspace) => ({
                id: `workspace-${workspace.id}`,
                group: 'workspaces' as const,
                title: {
                    path: [
                        { key: 'socialhq.workspace_switcher.switch_to' },
                        { text: workspace.name },
                    ],
                },
                icon: IconSwitchHorizontal,
                run: () => router.post(switchMethod.url(workspace.id)),
            }));
    });
};
