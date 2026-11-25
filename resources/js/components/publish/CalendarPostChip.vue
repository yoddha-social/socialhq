<script setup lang="ts">
import {
    IconAlertCircle,
    IconCircleCheck,
    IconHourglass,
    IconListNumbers,
    IconPencil,
    IconPin,
    IconPlayerPlayFilled,
    IconRepeat,
    IconSend,
} from '@tabler/icons-vue';
import { computed, nextTick, ref, type Component } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import PostTimelineCard from '@/components/publish/PostTimelineCard.vue';
import { postStatusTone } from '@/components/socialhq/postStatusTone';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCalendarDragContext } from '@/composables/calendar/useCalendarDrag';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { isImage, isVideo } from '@/lib/mediaType';
import { htmlToPlainText } from '@/lib/utils';
import { videoFrameUrl } from '@/lib/videoFrame';
import { PostStatus, ScheduleMode } from '@/types/post';
import type { CalendarPost, PublishTab } from '@/types/publish';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    post: CalendarPost;
    timezone: string;
    compact?: boolean;
    // Social HQ: a chip alone in its hour also shows the post's first line (PATCHES.md row 226).
    rich?: boolean;
}>();

const { draggingPostId, vCalendarPost } = useCalendarDragContext();

const isDragging = computed(() => draggingPostId.value === props.post.id);

const card = ref<InstanceType<typeof PostTimelineCard> | null>(null);
const cardMounted = ref(false);

/**
 * A click opens the post's details. The card that holds the details dialog
 * mounts on the first click only, so a busy calendar doesn't mount one per chip.
 */
const openDetails = async (): Promise<void> => {
    cardMounted.value = true;
    await nextTick();
    card.value?.openDetails();
};

const SENT_STATUSES: readonly string[] = [
    PostStatus.Published,
    PostStatus.Failed,
];

const tab = computed((): PublishTab => {
    if (SENT_STATUSES.includes(props.post.status)) {
        return 'sent';
    }

    if (props.post.status === PostStatus.Draft) {
        return 'drafts';
    }

    return props.post.status === PostStatus.PendingApproval
        ? 'approvals'
        : 'queue';
});

const channels = computed(() =>
    props.post.platform
        ? (props.post.social_account?.display_label ??
          getPlatformLabel(props.post.platform))
        : '',
);

const time = computed(() =>
    date.formatTimeInTimezone(props.post.calendar_at, props.timezone),
);

const text = computed(() => htmlToPlainText(props.post.content ?? ''));

const thumbnail = computed(
    () =>
        (props.post.media ?? []).find(
            (item) => isImage(item) || isVideo(item),
        ) ?? null,
);

type StatusKey =
    | 'draft'
    | 'pending_approval'
    | 'queue'
    | 'custom'
    | 'recurring'
    | 'publishing'
    | 'published'
    | 'failed';

interface StatusMarker {
    icon: Component;
    tone: string;
    testId: string;
}

const STATUS_MARKERS: Record<StatusKey, StatusMarker> = {
    draft: { icon: IconPencil, tone: 'text-muted-foreground', testId: 'calendar-post-draft' },
    pending_approval: { icon: IconHourglass, tone: 'text-muted-foreground', testId: 'calendar-post-pending' },
    queue: { icon: IconListNumbers, tone: 'text-muted-foreground', testId: 'post-schedule-mode' },
    custom: { icon: IconPin, tone: 'text-muted-foreground', testId: 'post-schedule-mode' },
    recurring: { icon: IconRepeat, tone: 'text-muted-foreground', testId: 'calendar-post-recurring' },
    publishing: { icon: IconSend, tone: 'text-muted-foreground', testId: 'calendar-post-publishing' },
    published: { icon: IconCircleCheck, tone: 'text-success', testId: 'calendar-post-published' },
    failed: { icon: IconAlertCircle, tone: 'text-destructive', testId: 'calendar-post-failed' },
};

const statusKey = computed((): StatusKey => {
    const { status, schedule_mode, recurrence_frequency } = props.post;

    if (status !== PostStatus.Scheduled) {
        return status as StatusKey;
    }

    if (recurrence_frequency) {
        return 'recurring';
    }

    return schedule_mode === ScheduleMode.Queue ? 'queue' : 'custom';
});

const statusMarker = computed(() => STATUS_MARKERS[statusKey.value]);

const statusLabelKey = computed(
    () => `calendar.post_status.${statusKey.value}`,
);

const chipClass = computed(() => [
    props.rich ? 'h-auto min-h-7 py-1' : 'h-7',
    isDragging.value
        ? 'border-transparent bg-muted *:invisible hover:bg-muted'
        : '',
]);

/**
 * Social HQ (PATCHES.md row 226): the first label's colour runs down the
 * chip's edge, the two statuses that wait for someone are spelled out, and a
 * chip with room shows the first line of the text.
 */
const stripe = computed(() => {
    const color = props.post.labels[0]?.color;

    return color && !props.compact
        ? { borderInlineStartColor: color, borderInlineStartWidth: '3px' }
        : undefined;
});

const LOUD_STATUSES: Partial<Record<StatusKey, string>> = {
    pending_approval: 'socialhq.calendar.chip_approval',
    failed: 'socialhq.calendar.chip_failed',
};

const loudKey = computed(() =>
    props.compact ? undefined : LOUD_STATUSES[statusKey.value],
);

// Social HQ: the status colours come from our one table (PATCHES.md row 334).
const tone = computed(() => postStatusTone(props.post.status));

const showsThumbnail = computed(
    () =>
        !props.compact &&
        thumbnail.value !== null &&
        (props.rich || !loudKey.value),
);

/**
 * Social HQ (PATCHES.md row 334): the word needs room beside the icon, the
 * whole time and any thumbnail; a narrower chip shows the status's dot instead
 * (the chip's row is the container). The tooltip and the names still say it.
 */
const loudRoom = computed(() =>
    showsThumbnail.value
        ? { word: '@max-[11rem]:hidden', dot: '@min-[11rem]:hidden' }
        : { word: '@max-[9.5rem]:hidden', dot: '@min-[9.5rem]:hidden' },
);

const firstLine = computed(
    () =>
        text.value
            .split('\n')
            .map((line) => line.trim())
            .find((line) => line !== '') ?? '',
);
</script>

<template>
    <div class="contents">
        <button
            v-calendar-post="post"
            type="button"
            v-bind="$attrs"
            class="flex min-w-0 shrink-0 flex-col justify-center rounded-lg border border-border-strong bg-card px-1.5 text-start shadow-[0_1px_0_rgba(0,0,0,0.03)] transition-control hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[located]:ring-2 data-[located]:ring-primary [&[data-calendar-draggable=true]]:cursor-grab"
            :class="chipClass"
            :style="stripe"
            :aria-label="
                [
                    channels,
                    time,
                    $t(statusLabelKey),
                    text || $t('calendar.no_content'),
                ].join(' · ')
            "
            :data-dragging="isDragging || undefined"
            :data-testid="`calendar-post-${post.id}`"
            @click="openDetails"
        >
          <span
            class="flex min-w-0 items-center"
            :class="compact ? 'justify-center gap-0.5' : '@container gap-1.5'"
          >
            <span
                class="flex min-w-0 items-center gap-1"
                :class="{ 'flex-1': !compact }"
            >
                <PlatformBrandIcon
                    v-if="post.platform"
                    :platform="post.platform"
                    colored
                    class="size-4 shrink-0"
                    :data-testid="`calendar-post-platform-${post.id}`"
                />
                <span
                    v-if="!compact"
                    class="min-w-0 truncate text-xs font-medium text-muted-foreground"
                    >{{ time }}</span
                >
            </span>
            <Tooltip :delay-duration="200">
                <TooltipTrigger as-child>
                    <span
                        class="flex shrink-0 items-center"
                        :class="statusMarker.tone"
                        :aria-label="$t(statusLabelKey)"
                        :data-testid="`${statusMarker.testId}-${post.id}`"
                        :data-mode="statusKey"
                    >
                        <template v-if="loudKey">
                            <Badge
                                :variant="tone.badge"
                                class="h-4 rounded border-0 px-1 text-[10px] leading-4 font-semibold tracking-wide uppercase"
                                :class="loudRoom.word"
                                >{{ $t(loudKey) }}</Badge
                            >
                            <span
                                class="m-0.5 size-2 rounded-full"
                                :class="[tone.dot, loudRoom.dot]"
                                aria-hidden="true"
                            />
                        </template>
                        <component
                            :is="statusMarker.icon"
                            v-else-if="compact"
                            class="size-3"
                            aria-hidden="true"
                        />
                        <span
                            v-else
                            class="m-0.5 size-2 rounded-full"
                            :class="tone.dot"
                            aria-hidden="true"
                        />
                    </span>
                </TooltipTrigger>
                <TooltipContent>{{ $t(statusLabelKey) }}</TooltipContent>
            </Tooltip>
            <span
                v-if="showsThumbnail && thumbnail"
                class="relative size-5 shrink-0 overflow-hidden rounded bg-secondary"
                :data-testid="`calendar-post-thumbnail-${post.id}`"
            >
                <img
                    v-if="isImage(thumbnail)"
                    :src="thumbnail.url"
                    alt=""
                    loading="lazy"
                    class="size-full object-cover"
                />
                <template v-else>
                    <video
                        :src="videoFrameUrl(thumbnail)"
                        class="size-full object-cover"
                        muted
                        playsinline
                        preload="metadata"
                    />
                    <IconPlayerPlayFilled
                        aria-hidden="true"
                        class="absolute top-1/2 left-1/2 size-3.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-black/60 p-0.5 text-white"
                    />
                </template>
            </span>
          </span>
          <span
            v-if="rich && firstLine"
            class="mt-0.5 block min-w-0 truncate text-xs leading-4 text-foreground"
            :data-testid="`calendar-post-text-${post.id}`"
            >{{ firstLine }}</span
          >
        </button>
        <div v-if="cardMounted" hidden>
            <PostTimelineCard
                ref="card"
                :post="post"
                :tab="tab"
                :display-timezone="timezone"
                :movable="false"
            />
        </div>
    </div>
</template>
