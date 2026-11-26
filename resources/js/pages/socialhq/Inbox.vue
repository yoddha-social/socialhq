<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    IconCalendarStats,
    IconChevronRight,
    IconFlag,
    IconInbox,
    IconMessageReport,
    IconPalette,
    IconSend,
    IconTextCaption,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, type Component } from 'vue';

import EmptyState from '@/components/EmptyState.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import { localizedDay } from '@/components/socialhq/localizedDay';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { index as postsIndex } from '@/routes/app/posts';
import { show as planShow } from '@/routes/socialhq/plan';
import { index as eventsIndex } from '@/routes/socialhq/plan/events';
import { show as itemShow } from '@/routes/socialhq/plan/items';

interface PlanRow {
    id: number;
    month: string;
    by: string | null;
    at: string | null;
}

interface ItemRow {
    id: number;
    title: string;
    scheduled_on: string;
    month: string | null;
    platform: string | null;
}

interface BriefRow {
    id: number;
    item_id: number;
    title: string | null;
    kind: 'graphic' | 'video';
    round: number;
    scheduled_on: string | null;
    assignee: string | null;
}

interface PostRow {
    id: string;
    excerpt: string;
    scheduled_at: string | null;
    channel: string | null;
    platform: string | null;
}

interface EventFlagRow {
    id: number;
    name: string | null;
    starts_on: string | null;
    note: string;
    by: string | null;
}

const props = defineProps<{
    sections: {
        plans: PlanRow[];
        copy: ItemRow[];
        creatives: BriefRow[];
        posts: PostRow[];
        event_flags: EventFlagRow[];
        copy_changes: ItemRow[];
        creative_changes: BriefRow[];
    };
}>();

interface Row {
    key: string;
    href: string;
    title: string;
    meta: string;
}

interface Section {
    key: string;
    icon: Component;
    rows: Row[];
}

const day = (value: string | null): string =>
    value ? localizedDay(value).format('ddd D MMM') : '';

const itemRows = (rows: ItemRow[]): Row[] =>
    rows.map((row) => ({
        key: `item-${row.id}`,
        href: itemShow.url(row.id),
        title: row.title,
        meta: day(row.scheduled_on),
    }));

const briefRows = (rows: BriefRow[]): Row[] =>
    rows.map((row) => ({
        key: `brief-${row.id}`,
        href: itemShow.url(row.item_id),
        title: row.title ?? '',
        meta: [
            day(row.scheduled_on),
            row.round > 0
                ? `${trans(`socialhq_production.brief.kinds.${row.kind}`)} · ${trans('socialhq.inbox.round', { round: String(row.round) })}`
                : trans(`socialhq_production.brief.kinds.${row.kind}`),
        ]
            .filter(Boolean)
            .join(' · '),
    }));

const sections = computed<Section[]>(() =>
    [
        {
            key: 'plans',
            icon: IconCalendarStats,
            rows: props.sections.plans.map((plan) => ({
                key: `plan-${plan.id}`,
                href: planShow.url(plan.month),
                title: localizedDay(`${plan.month}-01`).format('MMMM YYYY'),
                meta: plan.by
                    ? trans('socialhq.inbox.sent_by', {
                          name: plan.by,
                          date: plan.at ? date.formatDate(plan.at) : '',
                      })
                    : '',
            })),
        },
        {
            key: 'copy',
            icon: IconTextCaption,
            rows: itemRows(props.sections.copy),
        },
        {
            key: 'creatives',
            icon: IconPalette,
            rows: briefRows(props.sections.creatives),
        },
        {
            key: 'posts',
            icon: IconSend,
            rows: props.sections.posts.map((post) => ({
                key: `post-${post.id}`,
                href: postsIndex.url({ query: { tab: 'approvals' } }),
                title: post.excerpt || trans('socialhq.inbox.no_text'),
                meta: [
                    post.channel,
                    post.scheduled_at
                        ? date.formatDateTime(post.scheduled_at)
                        : '',
                ]
                    .filter(Boolean)
                    .join(' · '),
            })),
        },
        {
            key: 'event_flags',
            icon: IconFlag,
            rows: props.sections.event_flags.map((flag) => ({
                key: `event-flag-${flag.id}`,
                href: eventsIndex.url({
                    query: flag.starts_on
                        ? { month: flag.starts_on.slice(0, 7) }
                        : {},
                }),
                title: [flag.name, flag.starts_on ? day(flag.starts_on) : '']
                    .filter(Boolean)
                    .join(' · '),
                meta: flag.by
                    ? trans('socialhq.inbox.flagged_by', {
                          name: flag.by,
                          note: flag.note,
                      })
                    : flag.note,
            })),
        },
        {
            key: 'copy_changes',
            icon: IconMessageReport,
            rows: itemRows(props.sections.copy_changes),
        },
        {
            key: 'creative_changes',
            icon: IconMessageReport,
            rows: briefRows(props.sections.creative_changes),
        },
    ].filter((section) => section.rows.length > 0),
);
</script>

<template>
    <Head :title="$t('socialhq.inbox.title')" />
    <AppLayout>
        <template #header>
            <HeaderTitle
                :title="$t('socialhq.inbox.title')"
                :icon="IconInbox"
            />
        </template>

        <div
            class="mx-auto grid w-full max-w-3xl gap-6 px-4 py-6 md:px-8"
            data-testid="inbox"
        >
            <p class="text-sm text-muted-foreground">
                {{ $t('socialhq.inbox.intro') }}
            </p>

            <EmptyState
                v-if="sections.length === 0"
                :icon="IconInbox"
                :title="$t('socialhq.inbox.empty_title')"
                :description="$t('socialhq.inbox.empty_description')"
                data-testid="inbox-empty"
            />

            <section
                v-for="section in sections"
                :key="section.key"
                class="grid gap-2"
                :aria-labelledby="`inbox-${section.key}-title`"
                :data-testid="`inbox-section-${section.key}`"
            >
                <h2
                    :id="`inbox-${section.key}-title`"
                    class="flex items-center gap-2 text-sm font-semibold"
                >
                    <component
                        :is="section.icon"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ $t(`socialhq.inbox.sections.${section.key}`) }}
                    <span
                        class="rounded-full bg-muted px-1.5 text-xs font-medium text-muted-foreground tabular-nums"
                        >{{ section.rows.length }}</span
                    >
                </h2>
                <ul class="divide-y rounded-xl border bg-card">
                    <li v-for="row in section.rows" :key="row.key">
                        <Link
                            :href="row.href"
                            class="flex items-center gap-3 px-4 py-3 transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring"
                            :data-testid="`inbox-row-${row.key}`"
                        >
                            <span class="grid min-w-0 flex-1 gap-0.5">
                                <span class="truncate text-sm font-medium">{{
                                    row.title
                                }}</span>
                                <span
                                    v-if="row.meta"
                                    class="truncate text-xs text-muted-foreground"
                                    >{{ row.meta }}</span
                                >
                            </span>
                            <IconChevronRight
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
