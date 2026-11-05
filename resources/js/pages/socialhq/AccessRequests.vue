<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { IconBuildingStore, IconUserPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { create as createWorkspaceRoute } from '@/routes/app/workspaces';
import {
    approve as approveRequest,
    decline as declineRequest,
} from '@/routes/socialhq/access-requests';

interface AccessRequestRow {
    id: number;
    name: string;
    email: string;
    requested_at: string | null;
}

interface WorkspaceOption {
    id: string;
    name: string;
}

const props = defineProps<{
    requests: AccessRequestRow[];
    workspaces: WorkspaceOption[];
}>();

const approving = ref<AccessRequestRow | null>(null);
const declining = ref<AccessRequestRow | null>(null);
const isApproveOpen = ref(false);
const isDeclineOpen = ref(false);
const isDeclining = ref(false);

const form = useForm<{ workspaces: string[]; is_admin: boolean }>({
    workspaces: [],
    is_admin: false,
});

/**
 * The server keys a refused workspace by its position (`workspaces.0`), and
 * a missing pick by `workspaces`: show whichever came back under the list.
 */
const workspacesError = computed((): string | undefined => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.workspaces ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('workspaces.'),
        )?.[1]
    );
});

const personLabel = (request: AccessRequestRow): string =>
    request.name || request.email;

const openApprove = (request: AccessRequestRow): void => {
    approving.value = request;
    form.reset();
    form.clearErrors();
    form.workspaces =
        props.workspaces.length === 1 ? [props.workspaces[0].id] : [];
    isApproveOpen.value = true;
};

const closeApprove = (): void => {
    isApproveOpen.value = false;
};

const toggleWorkspace = (workspaceId: string): void => {
    form.workspaces = form.workspaces.includes(workspaceId)
        ? form.workspaces.filter((id) => id !== workspaceId)
        : [...form.workspaces, workspaceId];
};

const setAdmin = (value: boolean): void => {
    form.is_admin = value;
};

const submitApprove = (): void => {
    if (approving.value === null) {
        return;
    }

    form.post(approveRequest.url(approving.value.id), {
        preserveScroll: true,
        onSuccess: closeApprove,
    });
};

const openDecline = (request: AccessRequestRow): void => {
    declining.value = request;
    isDeclineOpen.value = true;
};

const closeDecline = (): void => {
    isDeclineOpen.value = false;
};

const submitDecline = (): void => {
    if (declining.value === null) {
        return;
    }

    router.post(
        declineRequest.url(declining.value.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isDeclining.value = true;
            },
            onFinish: () => {
                isDeclining.value = false;
            },
            onSuccess: closeDecline,
        },
    );
};
</script>

<template>
    <Head :title="$t('socialhq.signup.requests.page_title')" />

    <SettingsLayout
        :title="$t('socialhq.signup.requests.title')"
        :description="$t('socialhq.signup.requests.description')"
    >
        <EmptyState
            v-if="requests.length === 0"
            :title="$t('socialhq.signup.requests.empty')"
            data-testid="socialhq-access-requests-empty"
        />

        <EmptyState
            v-else-if="workspaces.length === 0"
            :icon="IconBuildingStore"
            :title="$t('socialhq.signup.requests.no_workspaces_title')"
            :description="
                $t('socialhq.signup.requests.no_workspaces_description')
            "
            data-testid="socialhq-access-requests-no-workspaces"
        >
            <template #action>
                <Button as-child>
                    <Link
                        :href="createWorkspaceRoute()"
                        data-testid="socialhq-access-requests-create-workspace"
                    >
                        {{ $t('socialhq.signup.requests.create_workspace') }}
                    </Link>
                </Button>
            </template>
        </EmptyState>

        <ul
            v-else
            class="divide-y divide-border rounded-lg border border-border"
            data-testid="socialhq-access-requests"
        >
            <SettingsListRow
                v-for="request in requests"
                :key="request.id"
                :icon="IconUserPlus"
                :data-testid="`socialhq-access-request-${request.id}`"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-foreground">
                        {{ request.name }}
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ request.email }}
                        <template v-if="request.requested_at">
                            ·
                            {{
                                $t('socialhq.signup.requests.requested', {
                                    time: date.diffForHumans(
                                        request.requested_at,
                                    ),
                                })
                            }}
                        </template>
                    </p>
                </div>

                <template #actions>
                    <div class="flex shrink-0 gap-2">
                        <Button
                            variant="ghost"
                            :aria-label="
                                $t('socialhq.signup.requests.decline_label', {
                                    name: personLabel(request),
                                })
                            "
                            :data-testid="`socialhq-access-decline-${request.id}`"
                            @click="openDecline(request)"
                        >
                            {{ $t('socialhq.signup.requests.decline') }}
                        </Button>
                        <Button
                            :aria-label="
                                $t('socialhq.signup.requests.approve_label', {
                                    name: personLabel(request),
                                })
                            "
                            :data-testid="`socialhq-access-approve-${request.id}`"
                            @click="openApprove(request)"
                        >
                            {{ $t('socialhq.signup.requests.approve') }}
                        </Button>
                    </div>
                </template>
            </SettingsListRow>
        </ul>
    </SettingsLayout>

    <Dialog v-model:open="isApproveOpen">
        <DialogContent
            class="sm:max-w-lg"
            data-testid="socialhq-access-approve-dialog"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submitApprove">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            $t('socialhq.signup.requests.approve_title', {
                                name: approving?.name ?? '',
                            })
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t('socialhq.signup.requests.approve_description', {
                                name: approving?.name ?? '',
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium text-foreground">
                        {{ $t('socialhq.signup.requests.workspaces_label') }}
                    </legend>
                    <label
                        v-for="workspace in workspaces"
                        :key="workspace.id"
                        class="flex cursor-pointer items-center gap-3 rounded-md border border-border px-3 py-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                form.workspaces.includes(workspace.id)
                            "
                            :data-testid="`socialhq-access-workspace-${workspace.id}`"
                            @update:model-value="toggleWorkspace(workspace.id)"
                        />
                        <span class="truncate">{{ workspace.name }}</span>
                    </label>
                    <p
                        v-if="workspacesError"
                        class="text-sm text-destructive"
                        data-testid="socialhq-access-workspaces-error"
                    >
                        {{ workspacesError }}
                    </p>
                </fieldset>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex flex-col gap-1">
                        <Label for="socialhq-access-admin">
                            {{ $t('socialhq.signup.requests.admin_label') }}
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            {{ $t('socialhq.signup.requests.admin_hint') }}
                        </p>
                    </div>
                    <Switch
                        id="socialhq-access-admin"
                        class="shrink-0"
                        :model-value="form.is_admin"
                        data-testid="socialhq-access-admin"
                        @update:model-value="setAdmin(Boolean($event))"
                    />
                </div>
                <p
                    v-if="form.errors.is_admin"
                    class="-mt-3 text-sm text-destructive"
                    data-testid="socialhq-access-admin-error"
                >
                    {{ form.errors.is_admin }}
                </p>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeApprove">
                        {{ $t('socialhq.signup.requests.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :loading="form.processing"
                        data-testid="socialhq-access-approve-submit"
                    >
                        {{ $t('socialhq.signup.requests.confirm_approve') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isDeclineOpen">
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-access-decline-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('socialhq.signup.requests.decline_title', {
                            name: declining?.name ?? '',
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq.signup.requests.decline_description') }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="ghost" @click="closeDecline">
                    {{ $t('socialhq.signup.requests.cancel') }}
                </Button>
                <Button
                    variant="destructive"
                    :loading="isDeclining"
                    data-testid="socialhq-access-decline-submit"
                    @click="submitDecline"
                >
                    {{ $t('socialhq.signup.requests.confirm_decline') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
