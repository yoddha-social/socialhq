<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { IconAlertTriangle, IconChevronDown } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { index as billingIndex } from '@/routes/app/billing';
import { edit as editProfile } from '@/routes/app/profile';
import { destroy as destroyWorkspace } from '@/routes/app/workspaces';

const props = defineProps<{
    workspace: {
        id: string;
        name: string;
    };
    isOnlyWorkspace: boolean;
    otherMemberCount: number;
}>();

const page = usePage();
const isSelfHosted = computed(() => Boolean(page.props.selfHosted));

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
// Social HQ: collapsed until opened, so the settings page doesn't open on the
// red warning; the section's title is the toggle.
const expanded = ref(false);

const warningKey = computed((): string => {
    if (props.isOnlyWorkspace) {
        return 'settings.workspace.delete_only_description';
    }

    return isSelfHosted.value
        ? 'settings.workspace.delete_description_self_hosted'
        : 'settings.workspace.delete_description';
});

const confirmKey = computed((): string =>
    isSelfHosted.value
        ? 'settings.workspace.delete_confirm_description_self_hosted'
        : 'settings.workspace.delete_confirm_description',
);

const showMembersWarning = computed(
    (): boolean => !props.isOnlyWorkspace && props.otherMemberCount > 0,
);

const openDeleteModal = () => {
    deleteModal.value?.open({
        url: destroyWorkspace.url(props.workspace.id),
        confirmText: props.workspace.name,
    });
};
</script>

<template>
    <Collapsible
        v-model:open="expanded"
        as="section"
        class="flex flex-col gap-6"
        data-testid="workspace-danger-zone"
    >
        <div class="min-w-0">
            <h2 class="text-base leading-5 font-emphasis text-foreground">
                <CollapsibleTrigger
                    class="flex w-full cursor-pointer items-center justify-between gap-4 rounded-md text-start outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    data-testid="workspace-danger-zone-toggle"
                >
                    <span class="min-w-0">{{
                        $t('settings.workspace.delete_title')
                    }}</span>
                    <IconChevronDown
                        class="size-4 shrink-0 text-muted-foreground transition-transform"
                        :class="{ 'rotate-180': expanded }"
                        aria-hidden="true"
                    />
                </CollapsibleTrigger>
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ $t('settings.workspace.danger_description') }}
            </p>
        </div>

        <CollapsibleContent>
            <div
                class="flex flex-col gap-6"
                data-testid="workspace-danger-zone-content"
            >
                <div
                    class="flex items-start gap-2 rounded-xl bg-critical-subtle p-4 text-sm text-foreground"
                >
                    <IconAlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-destructive-text"
                    />
                    <div class="flex flex-col gap-1">
                        <p class="font-emphasis">
                            {{ $t('settings.workspace.delete_warning') }}
                        </p>
                        <p>
                            {{ $t(warningKey) }}
                            <template v-if="showMembersWarning">
                                {{
                                    $tChoice(
                                        'settings.workspace.delete_members_warning',
                                        otherMemberCount,
                                        { count: String(otherMemberCount) },
                                    )
                                }}
                            </template>
                        </p>
                    </div>
                </div>

                <div v-if="isOnlyWorkspace" class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="billingIndex()">
                            {{ $t('settings.workspace.delete_go_to_billing') }}
                        </Link>
                    </Button>
                    <Button variant="destructive" as-child>
                        <Link :href="editProfile()">
                            {{
                                $t(
                                    'settings.workspace.delete_go_to_delete_account',
                                )
                            }}
                        </Link>
                    </Button>
                </div>
                <Button
                    v-else
                    variant="destructive"
                    class="self-start"
                    data-testid="workspace-delete-button"
                    @click="openDeleteModal"
                >
                    {{ $t('settings.workspace.delete_action') }}
                </Button>
            </div>
        </CollapsibleContent>

        <ConfirmDeleteModal
            ref="deleteModal"
            :title="$t('settings.workspace.delete_confirm_title')"
            :description="
                otherMemberCount > 0
                    ? `${$t(confirmKey)} ${$tChoice('settings.workspace.delete_members_warning', otherMemberCount, { count: String(otherMemberCount) })}`
                    : $t(confirmKey)
            "
            :action="$t('settings.workspace.delete_action')"
            :cancel="$t('settings.workspace.delete_cancel')"
        />
    </Collapsible>
</template>
