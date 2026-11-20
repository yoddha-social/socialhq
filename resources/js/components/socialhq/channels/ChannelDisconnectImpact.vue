<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconChartBar,
    IconChevronDown,
    IconChevronUp,
    IconCircleCheck,
    IconExternalLink,
    IconList,
    IconRefresh,
    IconRepeat,
    IconTarget,
    IconTrash,
    IconWorld,
} from '@tabler/icons-vue';
import { computed, onMounted, ref } from 'vue';

import date from '@/date';
import { userTimezone } from '@/preferences';
import { publish } from '@/routes/app/channels';
import { show as disconnectImpact } from '@/routes/socialhq/channels/disconnect-impact';

interface DisconnectImpact {
    posts: {
        scheduled: number;
        pending_approval: number;
        drafts: number;
        failed: number;
        sent: number;
    };
    unsent: number;
    media: number;
    upcoming: { id: string; scheduled_at: string | null; excerpt: string }[];
    posting_times: number;
    posting_goal: number | null;
    plan_items: number;
    repurposes: number;
    network: string;
}

type StatusKey = keyof DisconnectImpact['posts'];

const STATUSES: { key: StatusKey; dot: string }[] = [
    { key: 'scheduled', dot: 'bg-primary-strong' },
    { key: 'pending_approval', dot: 'bg-warning' },
    { key: 'drafts', dot: 'bg-muted-foreground/50' },
    { key: 'failed', dot: 'bg-destructive' },
    { key: 'sent', dot: 'bg-success' },
];

const props = defineProps<{
    channelId: string;
}>();

const http = useHttp<Record<string, never>, DisconnectImpact>({});
const impact = ref<DisconnectImpact | null>(null);
const loading = ref(true);
const expanded = ref(false);

// The dialog works as before when the counts cannot be read.
onMounted(async (): Promise<void> => {
    try {
        impact.value = await http.get(disconnectImpact.url(props.channelId));
    } catch {
        impact.value = null;
    } finally {
        loading.value = false;
    }
});

const statuses = computed(() =>
    impact.value
        ? STATUSES.filter((status) => impact.value!.posts[status.key] > 0)
        : [],
);

// The lang key only: resolved with $t in the template.
const summaryKey = computed((): string => {
    const unsent = impact.value?.unsent ?? 0;
    const sent = impact.value?.posts.sent ?? 0;

    if (unsent && sent) {
        return 'socialhq.channels.impact.summary';
    }

    if (unsent || sent) {
        return unsent
            ? 'socialhq.channels.impact.summary_unsent'
            : 'socialhq.channels.impact.summary_sent';
    }

    return 'socialhq.channels.impact.summary_empty';
});

const nextScheduled = computed(
    () => impact.value?.upcoming[0]?.scheduled_at ?? null,
);

const when = (instant: string | null): string =>
    instant
        ? date.formatMonthDayTimeInTimezone(instant, userTimezone.value)
        : '';

const postsUrl = computed(() => publish.url(props.channelId));

const toggle = (): void => {
    expanded.value = !expanded.value;
};
</script>

<template>
    <p
        v-if="loading"
        class="text-sm text-muted-foreground"
        data-testid="disconnect-impact-loading"
    >
        {{ $t('socialhq.channels.impact.loading') }}
    </p>
    <div
        v-else-if="impact"
        class="rounded-xl border border-destructive/30 bg-destructive/5 p-3"
        data-testid="disconnect-impact"
    >
        <div class="flex items-start gap-2.5">
            <IconTrash
                class="mt-0.5 size-4 shrink-0 text-destructive"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 text-sm text-foreground">
                <p data-testid="disconnect-impact-summary">
                    {{
                        $t(summaryKey, {
                            unsent: $tChoice(
                                'socialhq.channels.impact.unsent',
                                impact.unsent,
                            ),
                            sent: $tChoice(
                                'socialhq.channels.impact.sent',
                                impact.posts.sent,
                            ),
                            media: $tChoice(
                                'socialhq.channels.impact.media',
                                impact.media,
                            ),
                        })
                    }}
                </p>
                <p
                    class="mt-1 flex items-start gap-1.5 text-muted-foreground"
                    data-testid="disconnect-impact-network"
                >
                    <IconCircleCheck
                        class="mt-0.5 size-4 shrink-0 text-success-text"
                        aria-hidden="true"
                    />
                    <span>{{
                        $t('socialhq.channels.impact.network_stays', {
                            network: impact.network,
                        })
                    }}</span>
                </p>
                <button
                    type="button"
                    class="mt-2 inline-flex items-center gap-1 text-[13px] font-emphasis text-primary-strong hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :aria-expanded="expanded"
                    data-testid="disconnect-impact-toggle"
                    @click="toggle"
                >
                    {{
                        expanded
                            ? $t('socialhq.channels.impact.hide')
                            : $t('socialhq.channels.impact.show')
                    }}
                    <IconChevronUp v-if="expanded" class="size-3.5" />
                    <IconChevronDown v-else class="size-3.5" />
                </button>
            </div>
        </div>

        <div
            v-if="expanded"
            class="mt-3 flex flex-col gap-4 border-t border-destructive/20 pt-3"
            data-testid="disconnect-impact-details"
        >
            <div
                v-if="statuses.length"
                class="grid grid-cols-1 gap-1.5 sm:grid-cols-2"
            >
                <div
                    v-for="status in statuses"
                    :key="status.key"
                    class="flex items-start gap-2 rounded-lg bg-background/70 px-2.5 py-2"
                    :data-testid="`disconnect-impact-${status.key}`"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full"
                        :class="status.dot"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 text-[13px]">
                        <span
                            class="font-emphasis text-foreground tabular-nums"
                            >{{
                                impact.posts[status.key].toLocaleString()
                            }}</span
                        >
                        {{
                            $t(
                                `socialhq.channels.impact.statuses.${status.key}`,
                            )
                        }}
                        <span
                            v-if="status.key === 'scheduled' && nextScheduled"
                            class="block text-xs text-muted-foreground"
                            >{{
                                $t('socialhq.channels.impact.next_scheduled', {
                                    when: when(nextScheduled),
                                })
                            }}</span
                        >
                        <span
                            v-else-if="status.key === 'pending_approval'"
                            class="block text-xs text-muted-foreground"
                            >{{
                                $t('socialhq.channels.impact.pending_hint')
                            }}</span
                        >
                        <span
                            v-else-if="status.key === 'sent'"
                            class="block text-xs text-muted-foreground"
                            >{{
                                $t('socialhq.channels.impact.sent_hint')
                            }}</span
                        >
                    </span>
                </div>
            </div>

            <div v-if="impact.upcoming.length">
                <p
                    class="mb-1.5 text-[11.5px] font-medium tracking-wide text-subtle-foreground uppercase"
                >
                    {{ $t('socialhq.channels.impact.upcoming') }}
                </p>
                <ul class="flex flex-col gap-1">
                    <li
                        v-for="post in impact.upcoming"
                        :key="post.id"
                        class="flex items-center gap-2.5 rounded-lg bg-background/70 px-2.5 py-2 text-[13px]"
                        data-testid="disconnect-impact-upcoming"
                    >
                        <span
                            class="shrink-0 whitespace-nowrap text-muted-foreground tabular-nums"
                            >{{ when(post.scheduled_at) }}</span
                        >
                        <span
                            class="line-clamp-1 min-w-0 break-words text-foreground"
                            >{{
                                post.excerpt ||
                                $t('socialhq.channels.impact.no_text')
                            }}</span
                        >
                    </li>
                </ul>
            </div>

            <div class="grid grid-cols-1 gap-3 text-[13px] sm:grid-cols-2">
                <div>
                    <p
                        class="mb-1 text-[11.5px] font-medium tracking-wide text-subtle-foreground uppercase"
                    >
                        {{ $t('socialhq.channels.impact.stops') }}
                    </p>
                    <ul
                        class="flex flex-col gap-1 text-foreground"
                        data-testid="disconnect-impact-stops"
                    >
                        <li class="flex items-start gap-1.5">
                            <IconList
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{
                                $tChoice(
                                    'socialhq.channels.impact.queue',
                                    impact.posting_times,
                                )
                            }}
                        </li>
                        <li
                            v-if="impact.posting_goal"
                            class="flex items-start gap-1.5"
                        >
                            <IconTarget
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{
                                $t('socialhq.channels.impact.goal', {
                                    count: String(impact.posting_goal),
                                })
                            }}
                        </li>
                        <li
                            v-if="impact.plan_items"
                            class="flex items-start gap-1.5"
                        >
                            <IconList
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{
                                $tChoice(
                                    'socialhq.channels.impact.plan_items',
                                    impact.plan_items,
                                )
                            }}
                        </li>
                        <li
                            v-if="impact.repurposes"
                            class="flex items-start gap-1.5"
                        >
                            <IconRepeat
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{
                                $tChoice(
                                    'socialhq.channels.impact.repurposes',
                                    impact.repurposes,
                                )
                            }}
                        </li>
                    </ul>
                </div>
                <div>
                    <p
                        class="mb-1 text-[11.5px] font-medium tracking-wide text-subtle-foreground uppercase"
                    >
                        {{ $t('socialhq.channels.impact.stays') }}
                    </p>
                    <ul class="flex flex-col gap-1 text-foreground">
                        <li class="flex items-start gap-1.5">
                            <IconWorld
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{
                                $t('socialhq.channels.impact.stays_network', {
                                    network: impact.network,
                                })
                            }}
                        </li>
                        <li class="flex items-start gap-1.5">
                            <IconChartBar
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ $t('socialhq.channels.impact.stays_insights') }}
                        </li>
                        <li class="flex items-start gap-1.5">
                            <IconRefresh
                                class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ $t('socialhq.channels.impact.stays_reconnect') }}
                        </li>
                    </ul>
                </div>
            </div>

            <a
                :href="postsUrl"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-1 self-start text-[13px] font-emphasis text-primary-strong hover:underline"
                data-testid="disconnect-impact-open-posts"
            >
                {{ $t('socialhq.channels.impact.open_posts') }}
                <IconExternalLink class="size-3.5" aria-hidden="true" />
            </a>
        </div>
    </div>
</template>
