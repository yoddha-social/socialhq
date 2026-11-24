<script setup lang="ts">
import {
    IconArrowDown,
    IconArrowUp,
    IconBulb,
    IconChevronDown,
    IconDots,
    IconTrash,
} from '@tabler/icons-vue';
import { computed, useId } from 'vue';

import type {
    Keyword,
    KeywordCheckProp,
    KeywordCheckRow,
} from '@/components/socialhq/keywords/keywords';
import KeywordVerdictPill from '@/components/socialhq/keywords/KeywordVerdictPill.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { formatFullNumber } from '@/lib/utils';

/**
 * One SEO keyword: its verdict, why and the fix on the second line, how
 * many recent posts say it, and (opened) where the brand knowledge says it.
 */

const emit = defineEmits<{
    toggle: [];
    makeMain: [];
    makeSupporting: [];
    remove: [];
    addEntry: [];
}>();

const props = defineProps<{
    keyword: Keyword;
    /** Its check, or null while the check loads. */
    row: KeywordCheckRow | null;
    checked: KeywordCheckProp['checked'] | null;
    open: boolean;
    canEdit: boolean;
}>();

const detailsId = `socialhq-keyword-details-${useId()}`;

/** The keyword in test ids, without spaces. */
const slug = computed(() => props.keyword.text.replace(/\s+/g, '-'));

const toggle = (): void => {
    emit('toggle');
};

const makeMain = (): void => {
    emit('makeMain');
};

const makeSupporting = (): void => {
    emit('makeSupporting');
};

const remove = (): void => {
    emit('remove');
};

const addEntry = (): void => {
    emit('addEntry');
};
</script>

<template>
    <li
        class="border-b border-border last:border-b-0"
        :data-testid="`socialhq-keyword-${slug}`"
    >
        <div class="flex items-start gap-1 px-3 py-2.5 hover:bg-muted/60">
            <button
                type="button"
                class="flex min-w-0 flex-1 flex-col gap-1 text-start"
                :aria-expanded="open"
                :aria-controls="detailsId"
                :aria-label="
                    $t('socialhq.keywords.row.details', {
                        keyword: keyword.text,
                    })
                "
                :data-testid="`socialhq-keyword-toggle-${slug}`"
                @click="toggle"
            >
                <span class="flex flex-wrap items-center gap-2">
                    <span
                        class="text-sm font-medium break-words text-foreground"
                    >
                        {{ keyword.text }}
                    </span>
                    <KeywordVerdictPill v-if="row" :verdict="row.verdict" />
                    <Skeleton v-else class="h-[22px] w-16 rounded-full" />
                </span>
                <span
                    v-if="row"
                    class="text-[13px] leading-snug text-muted-foreground"
                >
                    {{ row.reason }}
                    <span v-if="row.fix" class="text-subtle-foreground">
                        <IconBulb
                            class="inline size-3.5 align-[-2px]"
                            aria-hidden="true"
                        />
                        {{ row.fix }}
                    </span>
                </span>
            </button>
            <span class="flex shrink-0 items-center gap-1 pt-0.5">
                <span
                    v-if="row && checked"
                    class="me-1 text-xs text-muted-foreground tabular-nums"
                    :title="
                        $t('socialhq.keywords.row.posts_title', {
                            days: String(checked.days),
                        })
                    "
                    :data-testid="`socialhq-keyword-posts-${slug}`"
                >
                    {{
                        row.posts > 0
                            ? $tChoice(
                                  'socialhq.keywords.row.posts',
                                  row.posts,
                                  {
                                      count: formatFullNumber(row.posts),
                                  },
                              )
                            : $t('socialhq.keywords.row.no_posts')
                    }}
                </span>
                <DropdownMenu v-if="canEdit">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :aria-label="
                                $t('socialhq.keywords.row.more', {
                                    keyword: keyword.text,
                                })
                            "
                            :data-testid="`socialhq-keyword-menu-${slug}`"
                        >
                            <IconDots class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            v-if="keyword.main"
                            :data-testid="`socialhq-keyword-supporting-${slug}`"
                            @select="makeSupporting"
                        >
                            <IconArrowDown class="size-4" />
                            {{ $t('socialhq.keywords.menu.make_supporting') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-else
                            :data-testid="`socialhq-keyword-main-${slug}`"
                            @select="makeMain"
                        >
                            <IconArrowUp class="size-4" />
                            {{ $t('socialhq.keywords.menu.make_main') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            :data-testid="`socialhq-keyword-remove-${slug}`"
                            @select="remove"
                        >
                            <IconTrash class="size-4" />
                            {{ $t('socialhq.keywords.menu.remove') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <button
                    type="button"
                    class="inline-flex size-7 items-center justify-center rounded-md text-subtle-foreground hover:bg-accent"
                    tabindex="-1"
                    aria-hidden="true"
                    @click="toggle"
                >
                    <IconChevronDown
                        class="size-3.5 transition-transform"
                        :class="{ 'rotate-180': open }"
                    />
                </button>
            </span>
        </div>

        <div
            v-if="open && row"
            :id="detailsId"
            class="grid gap-4 border-t border-dashed border-border bg-muted/40 px-3 py-3 md:grid-cols-[1fr_260px]"
            :data-testid="`socialhq-keyword-details-${slug}`"
        >
            <div>
                <p
                    class="text-xs font-medium tracking-wide text-subtle-foreground uppercase"
                >
                    {{ $t('socialhq.keywords.row.found_title') }}
                </p>
                <ul v-if="row.found.length" class="mt-2 grid gap-2">
                    <li
                        v-for="(place, index) in row.found"
                        :key="index"
                        class="flex gap-2 text-[13px]"
                    >
                        <span
                            class="mt-0.5 h-fit shrink-0 rounded bg-card px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground ring-1 ring-border"
                        >
                            {{ place.label }}
                        </span>
                        <span class="min-w-0">
                            <span class="font-medium text-foreground">
                                {{ place.title }}
                            </span>
                            <span
                                class="block break-words text-muted-foreground"
                            >
                                {{ place.snippet }}
                            </span>
                        </span>
                    </li>
                </ul>
                <template v-else>
                    <p class="mt-2 text-[13px] text-muted-foreground">
                        {{
                            $t('socialhq.keywords.row.found_none', {
                                keyword: keyword.text,
                                entries: $tChoice(
                                    'socialhq.keywords.check.entries',
                                    checked?.entries ?? 0,
                                    {
                                        count: formatFullNumber(
                                            checked?.entries ?? 0,
                                        ),
                                    },
                                ),
                                documents: $tChoice(
                                    'socialhq.keywords.check.documents',
                                    checked?.documents ?? 0,
                                    {
                                        count: formatFullNumber(
                                            checked?.documents ?? 0,
                                        ),
                                    },
                                ),
                            })
                        }}
                    </p>
                    <Button
                        v-if="canEdit"
                        variant="outline"
                        size="sm"
                        class="mt-2"
                        :data-testid="`socialhq-keyword-add-entry-${slug}`"
                        @click="addEntry"
                    >
                        {{ $t('socialhq.keywords.row.add_entry') }}
                    </Button>
                </template>
            </div>
            <div>
                <p
                    class="text-xs font-medium tracking-wide text-subtle-foreground uppercase"
                >
                    {{ $t('socialhq.keywords.row.recent_title') }}
                </p>
                <p class="mt-2 text-[13px] text-muted-foreground">
                    {{
                        row.posts > 0
                            ? $t('socialhq.keywords.row.recent_some', {
                                  count: formatFullNumber(row.posts),
                                  total: formatFullNumber(checked?.posts ?? 0),
                                  days: String(checked?.days ?? 0),
                              })
                            : $t('socialhq.keywords.row.recent_none', {
                                  total: formatFullNumber(checked?.posts ?? 0),
                                  days: String(checked?.days ?? 0),
                              })
                    }}
                </p>
                <p
                    class="mt-3 text-xs font-medium tracking-wide text-subtle-foreground uppercase"
                >
                    {{ $t('socialhq.keywords.row.group_title') }}
                </p>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    {{
                        keyword.main
                            ? $t('socialhq.keywords.row.group_main')
                            : $t('socialhq.keywords.row.group_supporting')
                    }}
                </p>
            </div>
        </div>
    </li>
</template>
