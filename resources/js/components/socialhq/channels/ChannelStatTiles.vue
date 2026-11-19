<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { publish } from '@/routes/app/channels';

export interface ChannelStats {
    scheduled: number;
    pending_approval: number;
    drafts: number;
    published: number;
    waiting: number;
}

const props = defineProps<{
    channelId: string;
    stats: ChannelStats;
    sent: number;
    goal: number | null;
}>();

type TileKey = 'scheduled' | 'pending_approval' | 'drafts' | 'published';

const TILES: { key: TileKey; tab: string; tone: string }[] = [
    { key: 'scheduled', tab: 'queue', tone: 'bg-primary-strong' },
    { key: 'pending_approval', tab: 'approvals', tone: 'bg-warning' },
    { key: 'drafts', tab: 'drafts', tone: 'bg-muted-foreground/50' },
    { key: 'published', tab: 'sent', tone: 'bg-success' },
];

const tabUrl = (tab: string): string =>
    publish.url(props.channelId, { query: { tab } });

const progress = computed(() =>
    props.goal ? Math.min(100, Math.round((props.sent / props.goal) * 100)) : 0,
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
            data-testid="channel-tiles"
        >
            <Link
                v-for="tile in TILES"
                :key="tile.key"
                :href="tabUrl(tile.tab)"
                class="rounded-xl border border-border p-4 transition-control hover:border-border-strong hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :data-testid="`channel-tile-${tile.key}`"
            >
                <p
                    class="flex items-center gap-1.5 text-[11.5px] font-medium tracking-wide text-subtle-foreground uppercase"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="tile.tone"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{
                        $t(`socialhq.channels.tiles.${tile.key}`)
                    }}</span>
                </p>
                <p
                    class="mt-1 font-heading text-[26px] leading-8 font-medium text-foreground tabular-nums"
                    :data-testid="`channel-tile-${tile.key}-count`"
                >
                    {{ stats[tile.key].toLocaleString() }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ $t(`socialhq.channels.tiles.${tile.key}_hint`) }}
                </p>
            </Link>
        </div>

        <div
            class="flex items-center gap-4 rounded-xl border border-border p-4"
            data-testid="channel-week"
        >
            <span class="shrink-0 text-[13px] font-emphasis text-foreground">{{
                $t('socialhq.channels.week.title')
            }}</span>
            <div
                class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="progress"
                :aria-label="$t('socialhq.channels.week.title')"
            >
                <span
                    class="block h-full rounded-full bg-success transition-[width]"
                    :style="{ width: `${progress}%` }"
                />
            </div>
            <span
                class="shrink-0 text-[12.5px] text-muted-foreground"
                data-testid="channel-week-progress"
            >
                {{
                    goal
                        ? $t('socialhq.channels.week.progress', {
                              sent: String(sent),
                              goal: String(goal),
                          })
                        : $t('socialhq.channels.week.no_goal', {
                              sent: String(sent),
                          })
                }}
            </span>
        </div>
    </div>
</template>
