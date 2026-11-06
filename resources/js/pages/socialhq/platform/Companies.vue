<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconBuilding, IconBuildingPlus } from '@tabler/icons-vue';
import { transChoice } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import { Badge } from '@/components/ui/badge';
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
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import {
    approve as approveCompany,
    decline as declineCompany,
    platformAi as platformAiRoute,
    restore as restoreCompany,
    suspend as suspendCompany,
} from '@/routes/socialhq/platform/companies';

type CompanyStatus = 'pending' | 'approved' | 'declined' | 'suspended';

interface CompanyRequestRow {
    id: number;
    name: string;
    owner_name: string | null;
    owner_email: string | null;
    verified: boolean;
    requested_at: string | null;
}

interface CompanyRow {
    id: number;
    name: string;
    owner_name: string | null;
    owner_email: string | null;
    workspaces_count: number;
    members_count: number;
    status: CompanyStatus;
    uses_platform_ai: boolean;
    decision_note: string | null;
    decided_at: string | null;
    created_at: string | null;
}

type NoteAction = 'decline' | 'suspend';

const props = defineProps<{
    tab: 'requests' | 'companies';
    requests: CompanyRequestRow[];
    companies: CompanyRow[];
}>();

const tab = ref<string>(props.tab);

const approvingId = ref<number | null>(null);
const noteAction = ref<NoteAction>('decline');
const noteTarget = ref<{ id: number; name: string } | null>(null);
const isNoteOpen = ref(false);
const restoring = ref<CompanyRow | null>(null);
const isRestoreOpen = ref(false);
const isRestoring = ref(false);

const noteForm = useForm({ note: '' });

const statusVariant = (
    status: CompanyStatus,
): 'success' | 'warning' | 'destructive-subtle' | 'secondary' => {
    switch (status) {
        case 'approved':
            return 'success';
        case 'suspended':
            return 'warning';
        case 'declined':
            return 'destructive-subtle';
        default:
            return 'secondary';
    }
};

const countsLabel = (company: CompanyRow): string =>
    [
        transChoice(
            'socialhq_companies.platform.workspaces',
            company.workspaces_count,
            { count: String(company.workspaces_count) },
        ),
        transChoice(
            'socialhq_companies.platform.members',
            company.members_count,
            { count: String(company.members_count) },
        ),
    ].join(' · ');

const noteTitle = computed((): string =>
    noteAction.value === 'decline'
        ? 'socialhq_companies.platform.decline_title'
        : 'socialhq_companies.platform.suspend_title',
);

const noteDescription = computed((): string =>
    noteAction.value === 'decline'
        ? 'socialhq_companies.platform.decline_description'
        : 'socialhq_companies.platform.suspend_description',
);

const approve = (request: CompanyRequestRow): void => {
    router.post(
        approveCompany.url(request.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                approvingId.value = request.id;
            },
            onFinish: () => {
                approvingId.value = null;
            },
        },
    );
};

const openNote = (
    action: NoteAction,
    company: { id: number; name: string },
): void => {
    noteAction.value = action;
    noteTarget.value = { id: company.id, name: company.name };
    noteForm.reset();
    noteForm.clearErrors();
    isNoteOpen.value = true;
};

const openDecline = (request: CompanyRequestRow): void => {
    openNote('decline', request);
};

const openSuspend = (company: CompanyRow): void => {
    openNote('suspend', company);
};

const closeNote = (): void => {
    isNoteOpen.value = false;
};

const submitNote = (): void => {
    if (noteTarget.value === null) {
        return;
    }

    const route =
        noteAction.value === 'decline' ? declineCompany : suspendCompany;

    noteForm.post(route.url(noteTarget.value.id), {
        preserveScroll: true,
        onSuccess: closeNote,
    });
};

const openRestore = (company: CompanyRow): void => {
    restoring.value = company;
    isRestoreOpen.value = true;
};

const closeRestore = (): void => {
    isRestoreOpen.value = false;
};

const submitRestore = (): void => {
    if (restoring.value === null) {
        return;
    }

    router.post(
        restoreCompany.url(restoring.value.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                isRestoring.value = true;
            },
            onFinish: () => {
                isRestoring.value = false;
            },
            onSuccess: closeRestore,
        },
    );
};

const setPlatformAi = (company: CompanyRow, enabled: boolean): void => {
    router.put(
        platformAiRoute.url(company.id),
        { enabled },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="$t('socialhq_companies.platform.page_title')" />

    <SettingsLayout
        :title="$t('socialhq_companies.platform.title')"
        :description="$t('socialhq_companies.platform.description')"
    >
        <Tabs v-model="tab" class="flex flex-col gap-6">
            <TabsList variant="line">
                <TabsTrigger
                    value="requests"
                    data-testid="socialhq-platform-tab-requests"
                >
                    {{ $t('socialhq_companies.platform.tabs.requests') }}
                    <Badge v-if="requests.length > 0" variant="secondary">
                        {{ requests.length }}
                    </Badge>
                </TabsTrigger>
                <TabsTrigger
                    value="companies"
                    data-testid="socialhq-platform-tab-companies"
                >
                    {{ $t('socialhq_companies.platform.tabs.companies') }}
                </TabsTrigger>
            </TabsList>

            <TabsContent value="requests" class="flex flex-col gap-4">
                <EmptyState
                    v-if="requests.length === 0"
                    :icon="IconBuildingPlus"
                    :title="$t('socialhq_companies.platform.requests_empty')"
                    data-testid="socialhq-platform-requests-empty"
                />
                <ul
                    v-else
                    class="flex flex-col gap-2"
                    data-testid="socialhq-platform-requests"
                >
                    <SettingsListRow
                        v-for="request in requests"
                        :key="request.id"
                        :icon="IconBuildingPlus"
                        :data-testid="`socialhq-platform-request-${request.id}`"
                    >
                        <p class="truncate text-sm font-medium text-foreground">
                            {{ request.name }}
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ request.owner_name }} · {{ request.owner_email }}
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span v-if="request.requested_at">
                                {{
                                    $t(
                                        'socialhq_companies.platform.requested',
                                        {
                                            time: date.diffForHumans(
                                                request.requested_at,
                                            ),
                                        },
                                    )
                                }}
                            </span>
                            <Badge
                                v-if="!request.verified"
                                variant="warning"
                                :title="
                                    $t(
                                        'socialhq_companies.platform.not_verified_hint',
                                    )
                                "
                                :data-testid="`socialhq-platform-unverified-${request.id}`"
                            >
                                {{
                                    $t(
                                        'socialhq_companies.platform.not_verified',
                                    )
                                }}
                            </Badge>
                        </p>

                        <template #actions>
                            <div class="flex shrink-0 gap-2">
                                <Button
                                    variant="ghost"
                                    :aria-label="
                                        $t(
                                            'socialhq_companies.platform.decline_label',
                                            { name: request.name },
                                        )
                                    "
                                    :data-testid="`socialhq-platform-decline-${request.id}`"
                                    @click="openDecline(request)"
                                >
                                    {{
                                        $t(
                                            'socialhq_companies.platform.decline',
                                        )
                                    }}
                                </Button>
                                <Button
                                    v-if="request.verified"
                                    :loading="approvingId === request.id"
                                    :aria-label="
                                        $t(
                                            'socialhq_companies.platform.approve_label',
                                            { name: request.name },
                                        )
                                    "
                                    :data-testid="`socialhq-platform-approve-${request.id}`"
                                    @click="approve(request)"
                                >
                                    {{
                                        $t(
                                            'socialhq_companies.platform.approve',
                                        )
                                    }}
                                </Button>
                            </div>
                        </template>
                    </SettingsListRow>
                </ul>
            </TabsContent>

            <TabsContent value="companies" class="flex flex-col gap-4">
                <EmptyState
                    v-if="companies.length === 0"
                    :icon="IconBuilding"
                    :title="$t('socialhq_companies.platform.companies_empty')"
                    data-testid="socialhq-platform-companies-empty"
                />
                <ul
                    v-else
                    class="flex flex-col gap-2"
                    data-testid="socialhq-platform-companies"
                >
                    <SettingsListRow
                        v-for="company in companies"
                        :key="company.id"
                        :icon="IconBuilding"
                        :data-testid="`socialhq-platform-company-${company.id}`"
                    >
                        <p
                            class="flex min-w-0 items-center gap-2 text-sm font-medium text-foreground"
                        >
                            <span class="truncate">{{ company.name }}</span>
                            <Badge
                                :variant="statusVariant(company.status)"
                                :data-testid="`socialhq-platform-status-${company.id}`"
                            >
                                {{
                                    $t(
                                        `socialhq_companies.platform.status.${company.status}`,
                                    )
                                }}
                            </Badge>
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ company.owner_name }} · {{ company.owner_email }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ countsLabel(company) }}
                            <template v-if="company.created_at">
                                ·
                                {{
                                    $t('socialhq_companies.platform.created', {
                                        time: date.diffForHumans(
                                            company.created_at,
                                        ),
                                    })
                                }}
                            </template>
                        </p>
                        <p
                            v-if="
                                company.decision_note &&
                                company.status !== 'approved'
                            "
                            class="text-xs whitespace-pre-line text-muted-foreground"
                        >
                            {{
                                $t('socialhq_companies.platform.note', {
                                    note: company.decision_note,
                                })
                            }}
                        </p>
                        <div
                            v-if="company.status !== 'declined'"
                            class="mt-1 flex items-center gap-2"
                        >
                            <Switch
                                :id="`socialhq-platform-ai-${company.id}`"
                                :model-value="company.uses_platform_ai"
                                :aria-label="
                                    $t(
                                        'socialhq_companies.platform.platform_ai_label',
                                        { name: company.name },
                                    )
                                "
                                :data-testid="`socialhq-platform-ai-${company.id}`"
                                @update:model-value="
                                    setPlatformAi(company, Boolean($event))
                                "
                            />
                            <Label
                                :for="`socialhq-platform-ai-${company.id}`"
                                class="text-sm font-normal"
                            >
                                {{
                                    $t(
                                        'socialhq_companies.platform.platform_ai',
                                    )
                                }}
                            </Label>
                        </div>

                        <template #actions>
                            <Button
                                v-if="company.status === 'approved'"
                                variant="ghost"
                                class="shrink-0"
                                :aria-label="
                                    $t(
                                        'socialhq_companies.platform.suspend_label',
                                        { name: company.name },
                                    )
                                "
                                :data-testid="`socialhq-platform-suspend-${company.id}`"
                                @click="openSuspend(company)"
                            >
                                {{ $t('socialhq_companies.platform.suspend') }}
                            </Button>
                            <Button
                                v-else-if="company.status === 'suspended'"
                                variant="outline"
                                class="shrink-0"
                                :aria-label="
                                    $t(
                                        'socialhq_companies.platform.restore_label',
                                        { name: company.name },
                                    )
                                "
                                :data-testid="`socialhq-platform-restore-${company.id}`"
                                @click="openRestore(company)"
                            >
                                {{ $t('socialhq_companies.platform.restore') }}
                            </Button>
                        </template>
                    </SettingsListRow>
                </ul>
            </TabsContent>
        </Tabs>
    </SettingsLayout>

    <Dialog v-model:open="isNoteOpen">
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-platform-note-dialog"
        >
            <form class="flex flex-col gap-5" @submit.prevent="submitNote">
                <DialogHeader>
                    <DialogTitle>
                        {{ $t(noteTitle, { name: noteTarget?.name ?? '' }) }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            $t(noteDescription, {
                                name: noteTarget?.name ?? '',
                            })
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="socialhq-platform-note">{{
                        $t('socialhq_companies.platform.note_label')
                    }}</Label>
                    <Textarea
                        id="socialhq-platform-note"
                        v-model="noteForm.note"
                        rows="3"
                        data-testid="socialhq-platform-note"
                    />
                    <InputError :message="noteForm.errors.note" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeNote">
                        {{ $t('socialhq_companies.platform.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        :loading="noteForm.processing"
                        data-testid="socialhq-platform-note-submit"
                    >
                        {{
                            noteAction === 'decline'
                                ? $t(
                                      'socialhq_companies.platform.confirm_decline',
                                  )
                                : $t(
                                      'socialhq_companies.platform.confirm_suspend',
                                  )
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="isRestoreOpen">
        <DialogContent
            class="sm:max-w-md"
            data-testid="socialhq-platform-restore-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('socialhq_companies.platform.restore_title', {
                            name: restoring?.name ?? '',
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        $t('socialhq_companies.platform.restore_description', {
                            name: restoring?.name ?? '',
                        })
                    }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="ghost" @click="closeRestore">
                    {{ $t('socialhq_companies.platform.cancel') }}
                </Button>
                <Button
                    :loading="isRestoring"
                    data-testid="socialhq-platform-restore-submit"
                    @click="submitRestore"
                >
                    {{ $t('socialhq_companies.platform.confirm_restore') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
