<script setup lang="ts">
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import date from '@/date';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';
import { htmlToPlainText } from '@/lib/utils';
import { PostStatus } from '@/types/post';
import type { CalendarPost } from '@/types/publish';

const props = defineProps<{
    post: CalendarPost;
    timezone: string;
}>();

const emit = defineEmits<{
    locate: [postId: string];
}>();

interface StatusTone {
    pill: string;
    dot: string;
}

const SCHEDULED_TONE: StatusTone = {
    pill: 'bg-primary-selected text-primary-text',
    dot: 'bg-primary-strong',
};

const DRAFT_TONE: StatusTone = {
    pill: 'bg-secondary text-muted-foreground',
    dot: 'bg-muted-foreground/50',
};

const STATUS_TONES: Record<string, StatusTone> = {
    [PostStatus.Scheduled]: SCHEDULED_TONE,
    [PostStatus.Publishing]: SCHEDULED_TONE,
    [PostStatus.Failed]: {
        pill: 'bg-destructive/10 text-destructive-text',
        dot: 'bg-destructive',
    },
    [PostStatus.PendingApproval]: {
        pill: 'bg-warning/15 text-warning',
        dot: 'bg-warning',
    },
    [PostStatus.Published]: {
        pill: 'bg-success-subtle text-success-text',
        dot: 'bg-success',
    },
};

const statusTone = computed(
    () => STATUS_TONES[props.post.status] ?? DRAFT_TONE,
);

const channelName = computed(
    () => props.post.social_account?.display_label ?? '',
);

const statusLabelKey = computed(() =>
    props.post.status === PostStatus.Scheduled
        ? 'calendar.status.scheduled'
        : `calendar.post_status.${props.post.status}`,
);

const when = computed(() => {
    const day = dayjs
        .utc(props.post.calendar_at)
        .tz(props.timezone)
        .locale(activeLocale.value.toLowerCase())
        .format('ddd D MMM');

    return `${day} · ${date.formatTimeInTimezone(props.post.calendar_at, props.timezone)}`;
});

const text = computed(() => htmlToPlainText(props.post.content ?? '').trim());

const locate = (): void => {
    emit('locate', props.post.id);
};
</script>

<template>
    <button
        type="button"
        class="flex w-full flex-col gap-2 rounded-xl border border-border bg-card p-3 text-start transition-control hover:border-border-strong hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        :data-testid="`calendar-list-post-${post.id}`"
        @click="locate"
    >
        <span class="flex min-w-0 items-center gap-2">
            <ChannelAvatar
                v-if="post.platform"
                :platform="post.platform"
                :name="channelName"
                :src="post.social_account?.avatar_url"
                :size="24"
            />
            <span
                class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                >{{ when }}</span
            >
            <span
                class="inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 text-[11px] leading-4 font-medium"
                :class="statusTone.pill"
                :data-testid="`calendar-list-status-${post.id}`"
            >
                <span
                    class="size-1.5 rounded-full"
                    :class="statusTone.dot"
                    aria-hidden="true"
                />
                {{ $t(statusLabelKey) }}
            </span>
        </span>
        <span
            class="line-clamp-2 text-[13px] leading-snug break-words text-foreground"
            :class="{ 'text-muted-foreground': !text }"
            >{{ text || $t('calendar.no_content') }}</span
        >
        <span
            v-if="post.labels.length"
            class="flex min-w-0 flex-wrap items-center gap-1"
        >
            <span
                v-for="label in post.labels.slice(0, 2)"
                :key="label.id"
                class="inline-flex max-w-full items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-[11px] leading-4 text-foreground"
            >
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: label.color }"
                />
                <span class="truncate">{{ label.name }}</span>
            </span>
        </span>
    </button>
</template>
