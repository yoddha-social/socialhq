<script setup lang="ts">
import { computed } from 'vue';

import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
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

const STATUS_TONES: Record<string, string> = {
    [PostStatus.Failed]: 'bg-destructive/10 text-destructive-text',
    [PostStatus.PendingApproval]: 'bg-warning/15 text-foreground',
    [PostStatus.Published]: 'bg-success/10 text-success',
};

const statusTone = computed(
    () =>
        STATUS_TONES[props.post.status] ?? 'bg-secondary text-muted-foreground',
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
        class="flex w-full flex-col gap-1.5 rounded-lg border border-border-strong bg-card px-2 py-2 text-start transition-control hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        :data-testid="`calendar-list-post-${post.id}`"
        @click="locate"
    >
        <span class="flex min-w-0 items-center gap-2">
            <img
                v-if="post.platform"
                :src="getPlatformLogo(post.platform)"
                :alt="getPlatformLabel(post.platform)"
                class="size-4 shrink-0 rounded-sm"
            />
            <span
                class="min-w-0 flex-1 truncate text-xs font-medium text-muted-foreground"
                >{{ when }}</span
            >
            <span
                class="shrink-0 rounded-full px-1.5 py-0.5 text-[11px] leading-none font-medium"
                :class="statusTone"
                :data-testid="`calendar-list-status-${post.id}`"
                >{{ $t(statusLabelKey) }}</span
            >
        </span>
        <span
            class="line-clamp-2 text-sm leading-[17.5px] break-words text-foreground"
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
                class="inline-flex max-w-full items-center gap-1 rounded-md border border-border px-1.5 py-0.5 text-[11px] leading-none text-muted-foreground"
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
