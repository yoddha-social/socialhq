<script setup lang="ts">
import { IconBook, IconPin, IconPlus, IconSearch } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import { needsAttention } from '@/components/socialhq/brandKnowledge';
import type { KnowledgeEntryRow } from '@/components/socialhq/KnowledgeEntryDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { filterBySearch } from '@/lib/search';
import { formatFullNumber } from '@/lib/utils';

/**
 * The knowledge entries on Brand knowledge: search, a filter by type or by
 * "needs attention" (marked for the AI but not read), and the list with
 * edit and delete for admins. The dialogs live on the page.
 */
const props = defineProps<{
    entries: KnowledgeEntryRow[];
    canEdit: boolean;
}>();

const emit = defineEmits<{
    add: [];
    edit: [entry: KnowledgeEntryRow];
    delete: [entry: KnowledgeEntryRow];
}>();

/** '' for all, 'attention', or a kind. The overview opens this tab on 'attention'. */
const filter = defineModel<string>('filter', { default: '' });

const search = ref('');

const attentionCount = computed(
    () => props.entries.filter(needsAttention).length,
);

/** The kinds that have entries, in the order they first appear. */
const kindCounts = computed(() => {
    const counts = new Map<string, number>();

    props.entries.forEach((entry) => {
        counts.set(entry.kind, (counts.get(entry.kind) ?? 0) + 1);
    });

    return [...counts.entries()];
});

const visibleEntries = computed(() => {
    const byFilter = props.entries.filter((entry) => {
        if (filter.value === '') {
            return true;
        }

        return filter.value === 'attention'
            ? needsAttention(entry)
            : entry.kind === filter.value;
    });

    return filterBySearch(byFilter, search.value, (entry) => [
        entry.title,
        entry.body,
    ]);
});

const pickFilter = (value: string): void => {
    filter.value = value;
};

const addEntry = (): void => {
    emit('add');
};

const editEntry = (entry: KnowledgeEntryRow): void => {
    emit('edit', entry);
};

const deleteEntry = (entry: KnowledgeEntryRow): void => {
    emit('delete', entry);
};

const chipClass = (active: boolean, attention = false): string[] => [
    'inline-flex h-7 items-center gap-1 rounded-full border px-3 text-sm transition-control',
    active
        ? attention
            ? 'border-amber-500/60 bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400'
            : 'border-primary bg-primary-selected text-primary-text'
        : attention
          ? 'border-amber-500/50 text-amber-700 hover:bg-accent dark:text-amber-400'
          : 'border-border-strong text-muted-foreground hover:bg-accent hover:text-foreground',
];
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-xl border border-border-strong bg-card p-5"
        data-testid="socialhq-knowledge"
    >
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
                @click="addEntry"
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

        <template v-else>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="relative w-full shrink-0 sm:w-56">
                    <IconSearch
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        class="pl-8"
                        :placeholder="$t('socialhq.brand.entries.search')"
                        :aria-label="$t('socialhq.brand.entries.search')"
                        data-testid="socialhq-knowledge-search"
                    />
                </div>
                <div
                    class="flex flex-wrap gap-2"
                    role="group"
                    :aria-label="$t('socialhq.brand.entries.filter_label')"
                >
                    <button
                        type="button"
                        :class="chipClass(filter === '')"
                        :aria-pressed="filter === ''"
                        data-testid="socialhq-knowledge-filter-all"
                        @click="pickFilter('')"
                    >
                        {{ $t('socialhq.brand.entries.all') }}
                        <span class="text-xs tabular-nums opacity-70">
                            {{ formatFullNumber(entries.length) }}
                        </span>
                    </button>
                    <button
                        v-if="attentionCount > 0"
                        type="button"
                        :class="chipClass(filter === 'attention', true)"
                        :aria-pressed="filter === 'attention'"
                        data-testid="socialhq-knowledge-filter-attention"
                        @click="pickFilter('attention')"
                    >
                        {{ $t('socialhq.brand.entries.attention') }}
                        <span class="text-xs tabular-nums opacity-70">
                            {{ formatFullNumber(attentionCount) }}
                        </span>
                    </button>
                    <button
                        v-for="[kind, count] in kindCounts"
                        :key="kind"
                        type="button"
                        :class="chipClass(filter === kind)"
                        :aria-pressed="filter === kind"
                        :data-testid="`socialhq-knowledge-filter-${kind}`"
                        @click="pickFilter(kind)"
                    >
                        {{ $t(`socialhq.brand.entries.kinds.${kind}`) }}
                        <span class="text-xs tabular-nums opacity-70">
                            {{ formatFullNumber(count) }}
                        </span>
                    </button>
                </div>
            </div>

            <p
                v-if="visibleEntries.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
                data-testid="socialhq-knowledge-no-match"
            >
                {{ $t('socialhq.brand.entries.no_match') }}
            </p>

            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="entry in visibleEntries"
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
                            <Badge v-if="entry.fit === 'off'" variant="outline">
                                {{ $t('socialhq.brand.entries.not_in_ai') }}
                            </Badge>
                            <Badge
                                v-else-if="entry.fit === 'included'"
                                variant="success"
                                :data-testid="`socialhq-knowledge-fit-${entry.id}`"
                            >
                                {{ $t('socialhq.brand.entries.fit.included') }}
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
                            v-if="canEdit && needsAttention(entry)"
                            class="text-sm text-amber-700 dark:text-amber-400"
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
                                    $t('socialhq.brand.entries.edit_label', {
                                        title: entry.title,
                                    })
                                "
                                :data-testid="`socialhq-knowledge-edit-${entry.id}`"
                                @click="editEntry(entry)"
                            >
                                {{ $t('socialhq.brand.entries.edit') }}
                            </Button>
                            <Button
                                variant="ghost"
                                :aria-label="
                                    $t('socialhq.brand.entries.delete_label', {
                                        title: entry.title,
                                    })
                                "
                                :data-testid="`socialhq-knowledge-delete-${entry.id}`"
                                @click="deleteEntry(entry)"
                            >
                                {{ $t('socialhq.brand.entries.delete') }}
                            </Button>
                        </div>
                    </template>
                </SettingsListRow>
            </ul>
        </template>
    </section>
</template>
