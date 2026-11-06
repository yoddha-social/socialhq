<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconBook, IconPin, IconPlus, IconSparkles } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import BrandPlanningSection from '@/components/socialhq/BrandPlanningSection.vue';
import KnowledgeEntryDialog, {
    type KnowledgeEntryRow,
} from '@/components/socialhq/KnowledgeEntryDialog.vue';
import type { PastCalendar } from '@/components/socialhq/oldCalendars';
import OldCalendarsSection from '@/components/socialhq/OldCalendarsSection.vue';
import type { PlanChannel } from '@/components/socialhq/plan/types';
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
import { Progress } from '@/components/ui/progress';
import { Textarea } from '@/components/ui/textarea';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { formatFullNumber } from '@/lib/utils';
import { destroy as destroyEntry } from '@/routes/socialhq/brand/entries';
import { update as updateProfile } from '@/routes/socialhq/brand/profile';

type ProfileField =
    | 'about'
    | 'audience'
    | 'voice'
    | 'words_to_use'
    | 'words_to_avoid';

const props = defineProps<{
    profile: Record<ProfileField, string>;
    entries: KnowledgeEntryRow[];
    usage: { used: number; budget: number; profileTrimmed: boolean };
    kinds: string[];
    pillars: string[];
    cadence: Record<string, number | null>;
    planChannels: PlanChannel[];
    canEdit: boolean;
    pastCalendars: PastCalendar[];
}>();

const profileFields: { field: ProfileField; rows: number; hint: boolean }[] = [
    { field: 'about', rows: 4, hint: true },
    { field: 'audience', rows: 3, hint: true },
    { field: 'voice', rows: 3, hint: true },
    { field: 'words_to_use', rows: 2, hint: false },
    { field: 'words_to_avoid', rows: 2, hint: false },
];

const profileForm = useForm({ ...props.profile });

/** Nothing written yet: no profile field and no entry. */
const isEmpty = computed(
    () =>
        props.entries.length === 0 &&
        Object.values(props.profile).every((value) => value.trim() === ''),
);

const usagePercent = computed(() =>
    Math.min(100, Math.round((props.usage.used / props.usage.budget) * 100)),
);

const profileErrorId = (field: ProfileField): string =>
    `socialhq-brand-${field}-error`;

/** aria-invalid and aria-describedby for a profile field, only while it has an error. */
const profileErrorAttributes = (
    field: ProfileField,
): Record<string, string | boolean | undefined> =>
    profileForm.errors[field]
        ? { 'aria-invalid': true, 'aria-describedby': profileErrorId(field) }
        : {};

const saveProfile = (): void => {
    profileForm.put(updateProfile.url(), {
        preserveScroll: true,
        onSuccess: () => {
            profileForm.defaults();
        },
    });
};

const startProfile = (): void => {
    document.getElementById('socialhq-brand-about')?.focus();
};

const isEntryDialogOpen = ref(false);
const editingEntry = ref<KnowledgeEntryRow | null>(null);
const deletingEntry = ref<KnowledgeEntryRow | null>(null);
const isDeleteOpen = ref(false);
const isDeleting = ref(false);

const openAddEntry = (): void => {
    editingEntry.value = null;
    isEntryDialogOpen.value = true;
};

const openEditEntry = (entry: KnowledgeEntryRow): void => {
    editingEntry.value = entry;
    isEntryDialogOpen.value = true;
};

const openDeleteEntry = (entry: KnowledgeEntryRow): void => {
    deletingEntry.value = entry;
    isDeleteOpen.value = true;
};

const closeDelete = (): void => {
    isDeleteOpen.value = false;
};

const confirmDelete = (): void => {
    if (deletingEntry.value === null) {
        return;
    }

    router.delete(destroyEntry.url(deletingEntry.value.id), {
        preserveScroll: true,
        onStart: () => {
            isDeleting.value = true;
        },
        onFinish: () => {
            isDeleting.value = false;
        },
        onSuccess: closeDelete,
    });
};
</script>

<template>
    <Head :title="$t('socialhq.brand.page_title')" />

    <SettingsLayout
        :title="$t('socialhq.brand.title')"
        :description="$t('socialhq.brand.description')"
    >
        <div class="flex flex-col gap-10" data-testid="socialhq-brand">
            <EmptyState
                v-if="isEmpty"
                :icon="IconSparkles"
                :title="$t('socialhq.brand.start.title')"
                :description="
                    canEdit
                        ? $t('socialhq.brand.start.description')
                        : $t('socialhq.brand.start.description_member')
                "
                data-testid="socialhq-brand-start"
            >
                <template v-if="canEdit" #action>
                    <Button
                        data-testid="socialhq-brand-start-action"
                        @click="startProfile"
                    >
                        {{ $t('socialhq.brand.start.action') }}
                    </Button>
                </template>
            </EmptyState>

            <template v-if="!isEmpty || canEdit">
                <p v-if="!canEdit" class="text-sm text-muted-foreground">
                    {{ $t('socialhq.brand.read_only') }}
                </p>

                <section
                    v-if="!isEmpty"
                    class="flex flex-col gap-2"
                    data-testid="socialhq-brand-usage"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <p
                            id="socialhq-brand-usage-label"
                            class="text-sm font-medium text-foreground"
                        >
                            {{ $t('socialhq.brand.usage.label') }}
                        </p>
                        <p class="text-sm text-muted-foreground tabular-nums">
                            {{
                                $t('socialhq.brand.usage.value', {
                                    used: formatFullNumber(usage.used),
                                    budget: formatFullNumber(usage.budget),
                                })
                            }}
                        </p>
                    </div>
                    <Progress
                        :model-value="usagePercent"
                        aria-labelledby="socialhq-brand-usage-label"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{ $t('socialhq.brand.usage.hint') }}
                    </p>
                    <p
                        v-if="usage.profileTrimmed"
                        class="text-sm text-muted-foreground"
                        data-testid="socialhq-brand-profile-trimmed"
                    >
                        {{ $t('socialhq.brand.usage.profile_trimmed') }}
                    </p>
                </section>

                <section class="flex flex-col gap-4">
                    <h2 class="text-base font-semibold text-foreground">
                        {{ $t('socialhq.brand.profile.title') }}
                    </h2>

                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="saveProfile"
                    >
                        <div
                            v-for="item in profileFields"
                            :key="item.field"
                            class="flex flex-col gap-2"
                        >
                            <Label :for="`socialhq-brand-${item.field}`">
                                {{ $t(`socialhq.brand.profile.${item.field}`) }}
                            </Label>
                            <Textarea
                                :id="`socialhq-brand-${item.field}`"
                                v-model="profileForm[item.field]"
                                v-bind="profileErrorAttributes(item.field)"
                                :rows="item.rows"
                                :disabled="!canEdit"
                                :placeholder="
                                    item.hint
                                        ? $t(
                                              `socialhq.brand.profile.${item.field}_placeholder`,
                                          )
                                        : undefined
                                "
                                :data-testid="`socialhq-brand-${item.field}`"
                            />
                            <p
                                v-if="profileForm.errors[item.field]"
                                :id="profileErrorId(item.field)"
                                class="text-sm text-destructive-text"
                            >
                                {{ profileForm.errors[item.field] }}
                            </p>
                        </div>

                        <div v-if="canEdit">
                            <Button
                                type="submit"
                                :disabled="!profileForm.isDirty"
                                :loading="profileForm.processing"
                                data-testid="socialhq-brand-save"
                            >
                                {{ $t('socialhq.brand.profile.save') }}
                            </Button>
                        </div>
                    </form>
                </section>

                <BrandPlanningSection
                    :pillars="pillars"
                    :cadence="cadence"
                    :channels="planChannels"
                    :can-edit="canEdit"
                />

                <section class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <h2 class="text-base font-semibold text-foreground">
                                {{ $t('socialhq.brand.entries.title') }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                {{ $t('socialhq.brand.entries.description') }}
                            </p>
                        </div>
                        <Button
                            v-if="canEdit"
                            class="shrink-0"
                            data-testid="socialhq-knowledge-add"
                            @click="openAddEntry"
                        >
                            <IconPlus class="size-4" />
                            {{ $t('socialhq.brand.entries.add') }}
                        </Button>
                    </div>

                    <EmptyState
                        v-if="entries.length === 0"
                        :title="
                            canEdit
                                ? $t('socialhq.brand.entries.empty')
                                : $t('socialhq.brand.entries.empty_member')
                        "
                        data-testid="socialhq-knowledge-empty"
                    />

                    <ul v-else class="flex flex-col gap-2">
                        <SettingsListRow
                            v-for="entry in entries"
                            :key="entry.id"
                            :icon="IconBook"
                            :data-testid="`socialhq-knowledge-${entry.id}`"
                        >
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <IconPin
                                        v-if="entry.pinned"
                                        class="size-4 shrink-0 text-muted-foreground"
                                        :aria-label="
                                            $t('socialhq.brand.entries.pinned')
                                        "
                                        role="img"
                                        :data-testid="`socialhq-knowledge-pinned-${entry.id}`"
                                    />
                                    <p
                                        class="truncate text-sm font-medium text-foreground"
                                    >
                                        {{ entry.title }}
                                    </p>
                                    <Badge variant="secondary">
                                        {{
                                            $t(
                                                `socialhq.brand.entries.kinds.${entry.kind}`,
                                            )
                                        }}
                                    </Badge>
                                    <Badge
                                        v-if="entry.fit === 'off'"
                                        variant="outline"
                                    >
                                        {{
                                            $t(
                                                'socialhq.brand.entries.not_in_ai',
                                            )
                                        }}
                                    </Badge>
                                    <Badge
                                        v-else-if="entry.fit === 'included'"
                                        variant="success"
                                        :data-testid="`socialhq-knowledge-fit-${entry.id}`"
                                    >
                                        {{
                                            $t(
                                                'socialhq.brand.entries.fit.included',
                                            )
                                        }}
                                    </Badge>
                                    <Badge
                                        v-else
                                        variant="warning"
                                        :data-testid="`socialhq-knowledge-fit-${entry.id}`"
                                    >
                                        {{
                                            $t(
                                                `socialhq.brand.entries.fit.${entry.fit}`,
                                            )
                                        }}
                                    </Badge>
                                </div>
                                <p
                                    class="line-clamp-2 text-sm whitespace-pre-line text-muted-foreground"
                                >
                                    {{ entry.body }}
                                </p>
                                <p
                                    v-if="
                                        canEdit &&
                                        (entry.fit === 'no_room' ||
                                            entry.fit === 'too_long')
                                    "
                                    class="text-sm text-muted-foreground"
                                >
                                    {{
                                        $t(
                                            `socialhq.brand.entries.fit_hint.${entry.fit}`,
                                        )
                                    }}
                                </p>
                            </div>

                            <template v-if="canEdit" #actions>
                                <div class="flex shrink-0 gap-2">
                                    <Button
                                        variant="ghost"
                                        :aria-label="
                                            $t(
                                                'socialhq.brand.entries.edit_label',
                                                { title: entry.title },
                                            )
                                        "
                                        :data-testid="`socialhq-knowledge-edit-${entry.id}`"
                                        @click="openEditEntry(entry)"
                                    >
                                        {{ $t('socialhq.brand.entries.edit') }}
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        :aria-label="
                                            $t(
                                                'socialhq.brand.entries.delete_label',
                                                { title: entry.title },
                                            )
                                        "
                                        :data-testid="`socialhq-knowledge-delete-${entry.id}`"
                                        @click="openDeleteEntry(entry)"
                                    >
                                        {{
                                            $t('socialhq.brand.entries.delete')
                                        }}
                                    </Button>
                                </div>
                            </template>
                        </SettingsListRow>
                    </ul>
                </section>

                <OldCalendarsSection
                    :calendars="pastCalendars"
                    :can-edit="canEdit"
                />
            </template>
        </div>
    </SettingsLayout>

    <KnowledgeEntryDialog
        v-model:open="isEntryDialogOpen"
        :entry="editingEntry"
        :kinds="kinds"
    />

    <Dialog v-model:open="isDeleteOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('socialhq.brand.entries.delete_title', {
                            title: deletingEntry?.title ?? '',
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq.brand.entries.delete_description') }}
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button type="button" variant="ghost" @click="closeDelete">
                    {{ $t('socialhq.brand.entries.dialog.cancel') }}
                </Button>
                <Button
                    variant="destructive"
                    :loading="isDeleting"
                    data-testid="socialhq-knowledge-delete-confirm"
                    @click="confirmDelete"
                >
                    {{ $t('socialhq.brand.entries.confirm_delete') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
