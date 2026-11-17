<script setup lang="ts">
import {
    IconArrowUpRight,
    IconBook,
    IconCheck,
    IconExclamationMark,
    IconFiles,
    IconPalette,
    IconPlus,
    IconTarget,
    IconWorld,
} from '@tabler/icons-vue';
import { computed, type Component } from 'vue';

import {
    CORE_PROFILE_FIELDS,
    needsAttention,
    openSuggestionCount,
    type BrandTab,
    type BrandUsage,
    type ProfileField,
} from '@/components/socialhq/brandKnowledge';
import type {
    BrandLookProp,
    WebsiteProp,
} from '@/components/socialhq/brandSources';
import type { KnowledgeDocumentsProp } from '@/components/socialhq/KnowledgeDocumentsSection.vue';
import type { KnowledgeEntryRow } from '@/components/socialhq/KnowledgeEntryDialog.vue';
import type { PastCalendar } from '@/components/socialhq/oldCalendars';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import date from '@/date';
import { formatFullNumber } from '@/lib/utils';

/**
 * The Overview tab of Brand knowledge: how ready the AI's picture of the
 * brand is, what changed lately and what is left to set up. Every number
 * comes from the page's props; each card opens the tab that changes it.
 */
const props = defineProps<{
    profile: Record<ProfileField, string>;
    entries: KnowledgeEntryRow[];
    usage: BrandUsage;
    look: BrandLookProp;
    pillars: string[];
    cadence: Record<string, number | null>;
    documents: KnowledgeDocumentsProp;
    pastCalendars: PastCalendar[];
    website: WebsiteProp;
    canEdit: boolean;
    isEmpty: boolean;
}>();

const emit = defineEmits<{
    open: [tab: BrandTab, filter?: string];
    addEntry: [];
    readWebsite: [];
    fillProfile: [];
}>();

const attentionCount = computed(
    () => props.entries.filter(needsAttention).length,
);
const openSuggestions = computed(() => openSuggestionCount(props.website));
const filled = (field: ProfileField): boolean =>
    props.profile[field].trim() !== '';
const profileComplete = computed(() => CORE_PROFILE_FIELDS.every(filled));
const profileStarted = computed(() =>
    Object.keys(props.profile).some((field) => filled(field as ProfileField)),
);
const lookDone = computed(
    () => props.look.logo !== null || props.look.colours.length > 0,
);
const postingChannels = computed(
    () =>
        Object.values(props.cadence).filter((perWeek) => (perWeek ?? 0) > 0)
            .length,
);
const strategyDone = computed(
    () => props.pillars.length > 0 && postingChannels.value > 0,
);
const sourceCount = computed(
    () =>
        props.documents.items.length +
        props.pastCalendars.length +
        (props.website.crawl ? 1 : 0),
);
const sourcesDone = computed(
    () => openSuggestions.value === 0 && sourceCount.value > 0,
);
const kindCount = computed(
    () => new Set(props.entries.map((entry) => entry.kind)).size,
);

type Health = 'empty' | 'attention' | 'ready';

/** The first thing that needs a look, as a lang key and its count. */
const firstIssue = computed<{ key: string; count: number } | null>(() => {
    if (props.usage.profileTrimmed) {
        return { key: 'trimmed', count: 0 };
    }

    if (attentionCount.value > 0) {
        return { key: 'entries', count: attentionCount.value };
    }

    if (openSuggestions.value > 0) {
        return { key: 'suggestions', count: openSuggestions.value };
    }

    return profileComplete.value ? null : { key: 'profile', count: 0 };
});

const health = computed<Health>(() => {
    if (props.isEmpty) {
        return 'empty';
    }

    return firstIssue.value ? 'attention' : 'ready';
});

type IdentityState = 'complete' | 'shortened' | 'in_progress' | 'not_started';

const identityState = computed<IdentityState>(() => {
    if (props.usage.profileTrimmed) {
        return 'shortened';
    }

    if (profileComplete.value && lookDone.value) {
        return 'complete';
    }

    return profileStarted.value || lookDone.value
        ? 'in_progress'
        : 'not_started';
});

interface Stat {
    key: string;
    tab: BrandTab;
    icon: Component;
    /** The big value: a number, or a lang key with its count. */
    value: { key?: string; count: number };
    hint: { key: string; count: number; warn?: boolean };
}

const stats = computed<Stat[]>(() => [
    {
        key: 'entries',
        tab: 'knowledge',
        icon: IconBook,
        value: { count: props.entries.length },
        hint:
            attentionCount.value > 0
                ? {
                      key: 'entries_attention',
                      count: attentionCount.value,
                      warn: true,
                  }
                : props.entries.length === 0
                  ? { key: 'entries_none', count: 0 }
                  : { key: 'entries_kinds', count: kindCount.value },
    },
    {
        key: 'sources',
        tab: 'sources',
        icon: IconFiles,
        value: { count: sourceCount.value },
        hint: { key: 'sources_hint', count: 0 },
    },
    {
        key: 'identity',
        tab: 'identity',
        icon: IconPalette,
        value: { key: `identity_${identityState.value}`, count: 0 },
        hint: {
            key: 'identity_hint',
            count: 0,
            warn: identityState.value === 'shortened',
        },
    },
    {
        key: 'strategy',
        tab: 'strategy',
        icon: IconTarget,
        value: { key: 'strategy_value', count: props.pillars.length },
        hint: { key: 'strategy_hint', count: postingChannels.value },
    },
]);

/** The three entries changed most recently. */
const recentEntries = computed(() =>
    [...props.entries]
        .sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))
        .slice(0, 3),
);

type SetupAction = 'edit' | 'start' | 'review' | 'view';

interface SetupRow {
    key: string;
    tab: BrandTab;
    done: boolean;
    hint: { key: string; count: number };
    action: SetupAction;
    filter?: string;
}

const actionFor = (done: boolean, needsReview: boolean): SetupAction => {
    if (!props.canEdit) {
        return 'view';
    }

    if (needsReview) {
        return 'review';
    }

    return done ? 'edit' : 'start';
};

const setupRows = computed<SetupRow[]>(() => [
    {
        key: 'profile',
        tab: 'identity',
        done: profileComplete.value && !props.usage.profileTrimmed,
        hint: {
            key: props.usage.profileTrimmed
                ? 'profile_trimmed'
                : 'profile_hint',
            count: 0,
        },
        action: actionFor(profileComplete.value, props.usage.profileTrimmed),
    },
    {
        key: 'look',
        tab: 'identity',
        done: lookDone.value,
        hint: { key: 'look_hint', count: 0 },
        action: actionFor(lookDone.value, false),
    },
    {
        key: 'strategy',
        tab: 'strategy',
        done: strategyDone.value,
        hint: { key: 'strategy_hint', count: 0 },
        action: actionFor(strategyDone.value, false),
    },
    {
        key: 'knowledge',
        tab: 'knowledge',
        done: props.entries.length > 0 && attentionCount.value === 0,
        hint:
            attentionCount.value > 0
                ? { key: 'knowledge_attention', count: attentionCount.value }
                : { key: 'knowledge_hint', count: 0 },
        action: actionFor(props.entries.length > 0, attentionCount.value > 0),
        filter: attentionCount.value > 0 ? 'attention' : undefined,
    },
    {
        key: 'sources',
        tab: 'sources',
        done: sourcesDone.value,
        hint:
            openSuggestions.value > 0
                ? { key: 'sources_suggestions', count: openSuggestions.value }
                : { key: 'sources_hint', count: 0 },
        action: actionFor(sourceCount.value > 0, openSuggestions.value > 0),
    },
]);

const setupDone = computed(
    () => setupRows.value.filter((row) => row.done).length,
);

const openTab = (tab: BrandTab, filter?: string): void => {
    emit('open', tab, filter);
};

const openSetupRow = (row: SetupRow): void => {
    emit('open', row.tab, row.filter);
};

const addEntry = (): void => {
    emit('addEntry');
};

const readWebsite = (): void => {
    emit('readWebsite');
};

const fillProfile = (): void => {
    emit('fillProfile');
};
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="socialhq-brand-overview">
        <section
            class="flex flex-col gap-4 rounded-xl border border-border-strong bg-card p-5 sm:flex-row sm:items-center sm:justify-between"
            :data-testid="
                isEmpty ? 'socialhq-brand-start' : 'socialhq-brand-glance'
            "
        >
            <div class="flex min-w-0 flex-col gap-1.5">
                <p
                    class="text-xs font-semibold tracking-wider text-primary-text uppercase"
                >
                    {{ $t('socialhq.brand.overview.eyebrow') }}
                </p>
                <h2 class="text-lg font-semibold text-foreground">
                    {{ $t('socialhq.brand.overview.title') }}
                </h2>
                <p class="max-w-xl text-sm text-muted-foreground">
                    <template v-if="!isEmpty">
                        {{ $t('socialhq.brand.overview.description') }}
                    </template>
                    <template v-else-if="canEdit">
                        {{ $t('socialhq.brand.start.description') }}
                    </template>
                    <template v-else>
                        {{ $t('socialhq.brand.start.description_member') }}
                    </template>
                </p>
                <div
                    v-if="isEmpty && canEdit"
                    class="mt-2 flex flex-wrap gap-2"
                >
                    <Button
                        v-if="website.aiAvailable"
                        data-testid="socialhq-brand-start-website"
                        @click="readWebsite"
                    >
                        <IconWorld class="size-4" />
                        {{ $t('socialhq.brand.overview.read_website') }}
                    </Button>
                    <Button
                        :variant="website.aiAvailable ? 'outline' : 'default'"
                        data-testid="socialhq-brand-start-action"
                        @click="fillProfile"
                    >
                        {{ $t('socialhq.brand.start.action') }}
                    </Button>
                </div>
            </div>

            <div
                class="flex shrink-0 items-center gap-3 rounded-lg border border-border bg-background px-4 py-3"
                data-testid="socialhq-brand-health"
            >
                <span
                    class="size-2.5 rounded-full"
                    :class="{
                        'bg-success ring-4 ring-success/20': health === 'ready',
                        'bg-warning ring-4 ring-warning/20':
                            health === 'attention',
                        'bg-subtle-foreground ring-4 ring-accent':
                            health === 'empty',
                    }"
                />
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-foreground">
                        {{ $t(`socialhq.brand.overview.health.${health}`) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="health === 'ready'">
                            {{
                                $t('socialhq.brand.overview.health.ready_hint')
                            }}
                        </template>
                        <template v-else-if="health === 'empty'">
                            {{
                                $t('socialhq.brand.overview.health.empty_hint')
                            }}
                        </template>
                        <template v-else-if="firstIssue">
                            {{
                                $tChoice(
                                    `socialhq.brand.overview.issues.${firstIssue.key}`,
                                    firstIssue.count,
                                    {
                                        count: formatFullNumber(
                                            firstIssue.count,
                                        ),
                                    },
                                )
                            }}
                        </template>
                    </p>
                </div>
            </div>
        </section>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <button
                v-for="stat in stats"
                :key="stat.key"
                type="button"
                class="flex min-w-0 flex-col items-start gap-1 rounded-xl border border-border-strong bg-card p-4 text-start transition-control hover:bg-accent"
                :data-testid="`socialhq-brand-stat-${stat.key}`"
                @click="openTab(stat.tab)"
            >
                <component
                    :is="stat.icon"
                    class="mb-2 size-5 text-muted-foreground"
                />
                <span class="text-xs text-muted-foreground">
                    {{ $t(`socialhq.brand.overview.stats.${stat.key}`) }}
                </span>
                <span
                    class="text-xl font-semibold text-foreground tabular-nums"
                >
                    <template v-if="stat.value.key">
                        {{
                            $tChoice(
                                `socialhq.brand.overview.stats.${stat.value.key}`,
                                stat.value.count,
                                { count: formatFullNumber(stat.value.count) },
                            )
                        }}
                    </template>
                    <template v-else>
                        {{ formatFullNumber(stat.value.count) }}
                    </template>
                </span>
                <span
                    class="flex items-center gap-1 text-xs"
                    :class="
                        stat.hint.warn
                            ? 'text-amber-700 dark:text-amber-400'
                            : 'text-muted-foreground'
                    "
                >
                    {{
                        $tChoice(
                            `socialhq.brand.overview.stats.${stat.hint.key}`,
                            stat.hint.count,
                            { count: formatFullNumber(stat.hint.count) },
                        )
                    }}
                    <IconArrowUpRight class="size-3 shrink-0" />
                </span>
            </button>
        </div>

        <div class="grid items-start gap-4 lg:grid-cols-2">
            <section
                class="flex flex-col gap-3 rounded-xl border border-border-strong bg-card p-5"
                aria-labelledby="socialhq-brand-recent-title"
                data-testid="socialhq-brand-recent"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-col gap-0.5">
                        <h3
                            id="socialhq-brand-recent-title"
                            class="text-sm font-semibold text-foreground"
                        >
                            {{ $t('socialhq.brand.overview.recent.title') }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t('socialhq.brand.overview.recent.description')
                            }}
                        </p>
                    </div>
                    <button
                        v-if="entries.length > 0"
                        type="button"
                        class="shrink-0 text-sm font-medium text-primary-text hover:text-primary-text-hover hover:underline"
                        @click="openTab('knowledge')"
                    >
                        {{ $t('socialhq.brand.overview.recent.view_all') }} →
                    </button>
                </div>

                <ul
                    v-if="recentEntries.length > 0"
                    class="flex flex-col divide-y divide-border"
                >
                    <li
                        v-for="entry in recentEntries"
                        :key="entry.id"
                        class="flex items-center gap-3 py-2.5"
                    >
                        <span
                            class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                        >
                            <IconBook class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-medium text-foreground"
                            >
                                {{ entry.title }}
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{
                                    $t(
                                        `socialhq.brand.entries.kinds.${entry.kind}`,
                                    )
                                }}<template v-if="entry.pinned">
                                    ·
                                    {{
                                        $t('socialhq.brand.entries.pinned')
                                    }}</template
                                ><template v-if="entry.updated_at">
                                    ·
                                    {{
                                        $t(
                                            'socialhq.brand.overview.recent.updated',
                                            {
                                                time: date.diffForHumans(
                                                    entry.updated_at,
                                                ),
                                            },
                                        )
                                    }}</template
                                >
                            </p>
                        </div>
                        <Badge
                            v-if="entry.fit === 'included'"
                            variant="success"
                        >
                            {{ $t('socialhq.brand.entries.fit.included') }}
                        </Badge>
                        <Badge
                            v-else-if="entry.fit === 'off'"
                            variant="outline"
                        >
                            {{ $t('socialhq.brand.entries.not_in_ai') }}
                        </Badge>
                        <Badge v-else variant="warning">
                            {{ $t(`socialhq.brand.entries.fit.${entry.fit}`) }}
                        </Badge>
                    </li>
                </ul>
                <p v-else class="py-3 text-sm text-muted-foreground">
                    {{
                        canEdit
                            ? $t('socialhq.brand.entries.empty')
                            : $t('socialhq.brand.entries.empty_member')
                    }}
                </p>

                <Button
                    v-if="canEdit"
                    variant="outline"
                    class="w-full"
                    data-testid="socialhq-brand-recent-add"
                    @click="addEntry"
                >
                    <IconPlus class="size-4" />
                    {{ $t('socialhq.brand.entries.add') }}
                </Button>
            </section>

            <section
                class="flex flex-col gap-3 rounded-xl border border-border-strong bg-card p-5"
                aria-labelledby="socialhq-brand-setup-title"
                data-testid="socialhq-brand-setup"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex flex-col gap-0.5">
                        <h3
                            id="socialhq-brand-setup-title"
                            class="text-sm font-semibold text-foreground"
                        >
                            {{ $t('socialhq.brand.overview.setup.title') }}
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            {{
                                $t('socialhq.brand.overview.setup.description')
                            }}
                        </p>
                    </div>
                    <Badge
                        :variant="
                            setupDone === setupRows.length
                                ? 'success'
                                : 'secondary'
                        "
                        class="shrink-0 tabular-nums"
                        data-testid="socialhq-brand-setup-count"
                    >
                        {{
                            $t('socialhq.brand.overview.setup.progress', {
                                done: formatFullNumber(setupDone),
                                total: formatFullNumber(setupRows.length),
                            })
                        }}
                    </Badge>
                </div>

                <ul class="flex flex-col divide-y divide-border">
                    <li
                        v-for="row in setupRows"
                        :key="row.key"
                        class="flex items-center gap-3 py-2.5"
                        :data-testid="`socialhq-brand-setup-${row.key}`"
                    >
                        <span
                            class="inline-flex size-6 shrink-0 items-center justify-center rounded-full"
                            :class="
                                row.done
                                    ? 'bg-success-subtle text-success-text'
                                    : row.action === 'review'
                                      ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400'
                                      : 'border border-dashed border-border-strong'
                            "
                        >
                            <IconCheck v-if="row.done" class="size-3.5" />
                            <IconExclamationMark
                                v-else-if="row.action === 'review'"
                                class="size-3.5"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-foreground">
                                {{
                                    $t(
                                        `socialhq.brand.overview.setup.rows.${row.key}`,
                                    )
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    $tChoice(
                                        `socialhq.brand.overview.setup.hints.${row.hint.key}`,
                                        row.hint.count,
                                        {
                                            count: formatFullNumber(
                                                row.hint.count,
                                            ),
                                        },
                                    )
                                }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 text-sm font-medium text-primary-text hover:text-primary-text-hover hover:underline"
                            :aria-label="
                                $t(
                                    'socialhq.brand.overview.setup.action_label',
                                    {
                                        action: $t(
                                            `socialhq.brand.overview.setup.actions.${row.action}`,
                                        ),
                                        part: $t(
                                            `socialhq.brand.overview.setup.rows.${row.key}`,
                                        ),
                                    },
                                )
                            "
                            :data-testid="`socialhq-brand-setup-${row.key}-open`"
                            @click="openSetupRow(row)"
                        >
                            {{
                                $t(
                                    `socialhq.brand.overview.setup.actions.${row.action}`,
                                )
                            }}
                            →
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
