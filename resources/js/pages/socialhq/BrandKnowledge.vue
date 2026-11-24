<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { IconLock, IconSparkles } from '@tabler/icons-vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import type { AudienceProp } from '@/components/socialhq/audience';
import AudienceSection from '@/components/socialhq/AudienceSection.vue';
import BrandContextCard from '@/components/socialhq/BrandContextCard.vue';
import {
    BRAND_TABS,
    openSuggestionCount,
    PROFILE_FIELDS,
    type BrandTab,
    type BrandUsage,
    type ProfileField,
} from '@/components/socialhq/brandKnowledge';
import BrandKnowledgeOverview from '@/components/socialhq/BrandKnowledgeOverview.vue';
import BrandLookSection from '@/components/socialhq/BrandLookSection.vue';
import BrandPlanningSection from '@/components/socialhq/BrandPlanningSection.vue';
import type {
    BrandLookProp,
    WebsiteProp,
} from '@/components/socialhq/brandSources';
import BrandWebsiteCard from '@/components/socialhq/BrandWebsiteCard.vue';
import type {
    KeywordCheckProp,
    KeywordsProp,
} from '@/components/socialhq/keywords/keywords';
import SeoKeywordsCard from '@/components/socialhq/keywords/SeoKeywordsCard.vue';
import KnowledgeCheckBox from '@/components/socialhq/KnowledgeCheckBox.vue';
import KnowledgeDocumentsSection, {
    type KnowledgeDocumentsProp,
} from '@/components/socialhq/KnowledgeDocumentsSection.vue';
import KnowledgeEntriesSection from '@/components/socialhq/KnowledgeEntriesSection.vue';
import KnowledgeEntryDialog, {
    type KnowledgeEntryRow,
} from '@/components/socialhq/KnowledgeEntryDialog.vue';
import type { PastCalendar } from '@/components/socialhq/oldCalendars';
import OldCalendarsSection from '@/components/socialhq/OldCalendarsSection.vue';
import type { PlanChannel } from '@/components/socialhq/plan/types';
import WebsiteReadSection from '@/components/socialhq/WebsiteReadSection.vue';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { formatFullNumber } from '@/lib/utils';
import { destroy as destroyEntry } from '@/routes/socialhq/brand/entries';
import { update as updateProfile } from '@/routes/socialhq/brand/profile';

const props = defineProps<{
    profile: Record<ProfileField, string>;
    entries: KnowledgeEntryRow[];
    usage: BrandUsage;
    kinds: string[];
    pillars: string[];
    cadence: Record<string, number | null>;
    planChannels: PlanChannel[];
    canEdit: boolean;
    documents: KnowledgeDocumentsProp;
    pastCalendars: PastCalendar[];
    audience: AudienceProp;
    look: BrandLookProp;
    website: WebsiteProp;
    keywords: KeywordsProp;
    /** Deferred: undefined until the keyword check has run. */
    keywordCheck?: KeywordCheckProp;
}>();

/** Rows and placeholder per profile field; the first three are wide. */
const profileFields: { field: ProfileField; rows: number; hint: boolean }[] =
    PROFILE_FIELDS.map((field) => ({
        field,
        rows: field === 'about' ? 4 : field.startsWith('words') ? 2 : 3,
        hint: !field.startsWith('words'),
    }));

const tab = ref<BrandTab>('overview');
/** The Knowledge tab's filter: '' for all, 'attention', or a kind. */
const knowledgeFilter = ref('');

const selectTab = (value: string | number): void => {
    if ((BRAND_TABS as readonly string[]).includes(String(value))) {
        tab.value = value as BrandTab;
    }
};

/** Opens the Strategy tab at the SEO keywords card, with the cursor in its field for admins. */
const openKeywords = (): void => {
    tab.value = 'strategy';

    nextTick(() => {
        document
            .getElementById('socialhq-seo-keywords')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

        if (props.canEdit) {
            document
                .getElementById('socialhq-keyword-input')
                ?.focus({ preventScroll: true });
        }
    });
};

const openTab = (next: BrandTab, filter?: string): void => {
    if (next === 'strategy' && filter === 'keywords') {
        openKeywords();

        return;
    }

    tab.value = next;

    if (next === 'knowledge') {
        knowledgeFilter.value = filter ?? '';
    }

    nextTick(() => {
        document
            .getElementById('socialhq-brand-tabs')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
};

// A link can open a tab (?tab=strategy) and the SEO keywords (?focus=keywords), as the
// note under AI copy does.
onMounted(() => {
    const query = new URLSearchParams(window.location.search);

    if (query.get('focus') === 'keywords') {
        openKeywords();
    } else {
        selectTab(query.get('tab') ?? '');
    }
});

/** Opens a tab and puts the cursor in one of its fields. */
const openTabAt = (next: BrandTab, fieldId: string): void => {
    openTab(next);
    nextTick(() => {
        document.getElementById(fieldId)?.focus({ preventScroll: true });
    });
};

const readWebsite = (): void => {
    openTabAt('sources', 'socialhq-website-url');
};

const fillProfile = (): void => {
    openTabAt('identity', 'socialhq-brand-about');
};

const openWebsiteSource = (): void => {
    openTab('sources');
};

const openSuggestions = computed(() => openSuggestionCount(props.website));

/** Nothing written yet: no profile field, no entry and no document. */
const isEmpty = computed(
    () =>
        props.entries.length === 0 &&
        props.documents.items.length === 0 &&
        Object.values(props.profile).every((value) => value.trim() === ''),
);

// ----- Brand identity: the profile here, the look in its section, one save bar for both

const profileForm = useForm({ ...props.profile });
const lookSection = ref<InstanceType<typeof BrandLookSection> | null>(null);

// A website suggestion accepted elsewhere on the page changes the profile:
// show it, unless the admin is editing the form.
watch(
    () => props.profile,
    (profile) => {
        if (!profileForm.isDirty) {
            profileForm.defaults({ ...profile });
            profileForm.reset();
        }
    },
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

const lookIsDirty = computed(() => lookSection.value?.form.isDirty ?? false);
const hasUnsavedIdentity = computed(
    () => props.canEdit && (profileForm.isDirty || lookIsDirty.value),
);
const isSavingIdentity = computed(
    () =>
        profileForm.processing || (lookSection.value?.form.processing ?? false),
);

const saveLook = (): void => {
    if (lookSection.value?.form.isDirty) {
        lookSection.value.save();
    }
};

/** Saves the profile, then the look: two requests, one after the other. */
const saveIdentity = (): void => {
    if (!profileForm.isDirty) {
        saveLook();

        return;
    }

    profileForm.put(updateProfile.url(), {
        preserveScroll: true,
        onSuccess: () => {
            profileForm.defaults();
            saveLook();
        },
    });
};

const discardIdentity = (): void => {
    profileForm.reset();
    profileForm.clearErrors();
    lookSection.value?.discard();
};

// ----- Knowledge entries: add, edit and delete dialogs

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

// ----- Check AI context

const isCheckOpen = ref(false);

const openCheck = (): void => {
    isCheckOpen.value = true;
};
</script>

<template>
    <Head :title="$t('socialhq.brand.page_title')" />

    <SettingsLayout
        wide
        :title="$t('socialhq.brand.title')"
        :description="$t('socialhq.brand.description')"
    >
        <template #actions>
            <Button
                variant="outline"
                data-testid="socialhq-brand-check-open"
                @click="openCheck"
            >
                <IconSparkles class="size-4" />
                {{ $t('socialhq.brand.check.open') }}
            </Button>
        </template>

        <div class="flex flex-col gap-6" data-testid="socialhq-brand">
            <p
                v-if="!canEdit"
                class="flex items-center gap-2 rounded-lg border border-border bg-muted px-4 py-3 text-sm text-muted-foreground"
                data-testid="socialhq-brand-read-only"
            >
                <IconLock class="size-4 shrink-0" />
                {{ $t('socialhq.brand.read_only') }}
            </p>

            <Tabs
                :model-value="tab"
                class="gap-6"
                @update:model-value="selectTab"
            >
                <TabsList
                    id="socialhq-brand-tabs"
                    variant="line"
                    class="scroll-mt-4"
                >
                    <TabsTrigger
                        v-for="name in BRAND_TABS"
                        :key="name"
                        :value="name"
                        :data-testid="`socialhq-brand-tab-${name}`"
                    >
                        {{ $t(`socialhq.brand.tabs.${name}`) }}
                        <span
                            v-if="name === 'sources' && openSuggestions > 0"
                            class="rounded-full bg-amber-50 px-1.5 text-xs text-amber-700 tabular-nums dark:bg-amber-950 dark:text-amber-400"
                            :aria-label="
                                $tChoice(
                                    'socialhq.brand.tabs.suggestions',
                                    openSuggestions,
                                    {
                                        count: formatFullNumber(
                                            openSuggestions,
                                        ),
                                    },
                                )
                            "
                        >
                            {{ formatFullNumber(openSuggestions) }}
                        </span>
                    </TabsTrigger>
                </TabsList>

                <div
                    class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_280px]"
                >
                    <div class="flex min-w-0 flex-col gap-6">
                        <TabsContent
                            value="overview"
                            force-mount
                            class="data-[state=inactive]:hidden"
                        >
                            <BrandKnowledgeOverview
                                :profile="profile"
                                :entries="entries"
                                :usage="usage"
                                :look="look"
                                :pillars="pillars"
                                :cadence="cadence"
                                :documents="documents"
                                :past-calendars="pastCalendars"
                                :website="website"
                                :can-edit="canEdit"
                                :is-empty="isEmpty"
                                :keyword-count="keywords.items.length"
                                :keyword-check="keywordCheck"
                                @open="openTab"
                                @add-entry="openAddEntry"
                                @read-website="readWebsite"
                                @fill-profile="fillProfile"
                            />
                        </TabsContent>

                        <TabsContent
                            value="knowledge"
                            force-mount
                            class="data-[state=inactive]:hidden"
                        >
                            <KnowledgeEntriesSection
                                v-model:filter="knowledgeFilter"
                                :entries="entries"
                                :can-edit="canEdit"
                                @add="openAddEntry"
                                @edit="openEditEntry"
                                @delete="openDeleteEntry"
                            />
                        </TabsContent>

                        <TabsContent
                            value="identity"
                            force-mount
                            class="flex flex-col gap-6 data-[state=inactive]:hidden"
                        >
                            <section
                                class="flex flex-col gap-4 rounded-xl border border-border-strong bg-card p-5"
                                data-testid="socialhq-brand-profile"
                            >
                                <div class="flex flex-col gap-1">
                                    <h2
                                        class="text-base font-semibold text-foreground"
                                    >
                                        {{ $t('socialhq.brand.profile.title') }}
                                    </h2>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            $t(
                                                'socialhq.brand.profile.description',
                                            )
                                        }}
                                    </p>
                                </div>

                                <form
                                    class="grid gap-4 md:grid-cols-2"
                                    @submit.prevent="saveIdentity"
                                >
                                    <div
                                        v-for="item in profileFields"
                                        :key="item.field"
                                        class="flex flex-col gap-2"
                                        :class="{
                                            'md:col-span-2':
                                                item.field === 'about',
                                        }"
                                    >
                                        <Label
                                            :for="`socialhq-brand-${item.field}`"
                                        >
                                            {{
                                                $t(
                                                    `socialhq.brand.profile.${item.field}`,
                                                )
                                            }}
                                        </Label>
                                        <Textarea
                                            :id="`socialhq-brand-${item.field}`"
                                            v-model="profileForm[item.field]"
                                            v-bind="
                                                profileErrorAttributes(
                                                    item.field,
                                                )
                                            "
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
                                            v-if="
                                                profileForm.errors[item.field]
                                            "
                                            :id="profileErrorId(item.field)"
                                            class="text-sm text-destructive-text"
                                        >
                                            {{ profileForm.errors[item.field] }}
                                        </p>
                                    </div>
                                </form>
                            </section>

                            <div
                                class="rounded-xl border border-border-strong bg-card p-5"
                            >
                                <BrandLookSection
                                    ref="lookSection"
                                    :look="look"
                                    :can-edit="canEdit"
                                    :inline-save="false"
                                />
                            </div>
                        </TabsContent>

                        <TabsContent
                            value="strategy"
                            force-mount
                            class="flex flex-col gap-6 data-[state=inactive]:hidden"
                        >
                            <div class="grid items-start gap-6 xl:grid-cols-2">
                                <div
                                    class="rounded-xl border border-border-strong bg-card p-5"
                                >
                                    <BrandPlanningSection
                                        :pillars="pillars"
                                        :cadence="cadence"
                                        :channels="planChannels"
                                        :can-edit="canEdit"
                                    />
                                </div>
                                <div
                                    class="rounded-xl border border-border-strong bg-card p-5"
                                >
                                    <AudienceSection
                                        :audience="audience"
                                        :can-edit="canEdit"
                                    />
                                </div>
                            </div>
                            <SeoKeywordsCard
                                :keywords="keywords"
                                :check="keywordCheck"
                                :can-edit="canEdit"
                                @add-entry="openAddEntry"
                            />
                        </TabsContent>

                        <TabsContent
                            value="sources"
                            force-mount
                            class="flex flex-col gap-6 data-[state=inactive]:hidden"
                        >
                            <div
                                v-if="canEdit"
                                class="rounded-xl border border-border-strong bg-card p-5"
                            >
                                <WebsiteReadSection
                                    :website="website"
                                    :can-edit="canEdit"
                                />
                            </div>
                            <div
                                class="rounded-xl border border-border-strong bg-card p-5"
                            >
                                <KnowledgeDocumentsSection
                                    :documents="documents"
                                    :can-edit="canEdit"
                                />
                            </div>
                            <div
                                class="rounded-xl border border-border-strong bg-card p-5"
                            >
                                <OldCalendarsSection
                                    :calendars="pastCalendars"
                                    :can-edit="canEdit"
                                />
                            </div>
                        </TabsContent>

                        <div
                            v-if="hasUnsavedIdentity"
                            class="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-xl border border-primary bg-card px-4 py-3 shadow-lg"
                            role="region"
                            :aria-label="$t('socialhq.brand.save_bar.label')"
                            data-testid="socialhq-brand-save-bar"
                        >
                            <p class="min-w-0 flex-1 text-sm text-foreground">
                                {{ $t('socialhq.brand.save_bar.message') }}
                            </p>
                            <Button
                                variant="ghost"
                                :disabled="isSavingIdentity"
                                data-testid="socialhq-brand-discard"
                                @click="discardIdentity"
                            >
                                {{ $t('socialhq.brand.save_bar.discard') }}
                            </Button>
                            <Button
                                :loading="isSavingIdentity"
                                data-testid="socialhq-brand-save"
                                @click="saveIdentity"
                            >
                                {{ $t('socialhq.brand.save_bar.save') }}
                            </Button>
                        </div>
                    </div>

                    <aside class="flex flex-col gap-4 lg:sticky lg:top-6">
                        <BrandContextCard
                            :usage="usage"
                            :keyword-count="keywords.items.length"
                            @check="openCheck"
                        />
                        <BrandWebsiteCard
                            v-if="canEdit"
                            :website="website"
                            @open="openWebsiteSource"
                        />
                    </aside>
                </div>
            </Tabs>
        </div>
    </SettingsLayout>

    <KnowledgeEntryDialog
        v-model:open="isEntryDialogOpen"
        :entry="editingEntry"
        :kinds="kinds"
    />

    <Dialog v-model:open="isCheckOpen">
        <DialogContent
            class="max-h-[85vh] overflow-y-auto sm:max-w-2xl"
            data-testid="socialhq-brand-check-dialog"
        >
            <DialogHeader>
                <DialogTitle>
                    {{ $t('socialhq.brand.check.title') }}
                </DialogTitle>
                <DialogDescription>
                    {{ $t('socialhq_knowledge.check.description') }}
                </DialogDescription>
            </DialogHeader>
            <KnowledgeCheckBox :show-heading="false" />
        </DialogContent>
    </Dialog>

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
