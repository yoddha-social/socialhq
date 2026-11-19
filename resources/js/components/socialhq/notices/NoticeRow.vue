<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconAlertCircle,
    IconAlertTriangle,
    IconArrowBackUp,
    IconBell,
    IconCircleCheck,
    IconHourglass,
    IconMessage,
    IconPlugConnected,
    IconPlugConnectedX,
    IconThumbUp,
} from '@tabler/icons-vue';
import { computed, type Component } from 'vue';

import type {
    NoticeAction,
    NoticeItem,
    NoticeTone,
} from '@/components/socialhq/notices/notices';
import dayjs from '@/dayjs';
import { activeLocale } from '@/language';

const props = defineProps<{
    notice: NoticeItem;
}>();

const emit = defineEmits<{
    act: [notice: NoticeItem];
}>();

const ICONS: Record<string, Component> = {
    post_published: IconCircleCheck,
    post_failed: IconAlertTriangle,
    channel_disconnected: IconPlugConnectedX,
    posts_at_risk: IconAlertCircle,
    post_note: IconMessage,
    approval_requested: IconHourglass,
    post_approved: IconThumbUp,
    post_rejected: IconArrowBackUp,
    channel_connected: IconPlugConnected,
};

const TONES: Record<NoticeTone, string> = {
    success: 'bg-success-subtle text-success-text',
    danger: 'bg-destructive/10 text-destructive-text',
    warning: 'bg-warning/15 text-warning',
    info: 'bg-primary-selected text-primary-text',
};

const icon = computed(() => ICONS[props.notice.kind] ?? IconBell);

const when = computed(() =>
    dayjs(props.notice.created_at)
        .locale(activeLocale.value.toLowerCase())
        .fromNow(),
);

const act = (): void => {
    emit('act', props.notice);
};

const actionClass =
    'inline-flex h-7 items-center rounded-md border border-border bg-card px-2.5 text-xs font-medium text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring';

const actionKey = (action: NoticeAction): string =>
    `${action.label}:${action.href}`;
</script>

<template>
    <div
        class="flex gap-3 rounded-lg px-3 py-2.5"
        :class="{ 'bg-primary-selected/40': !notice.read }"
        :data-read="notice.read || undefined"
        :data-testid="`notice-${notice.id}`"
    >
        <span
            class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full"
            :class="TONES[notice.tone]"
        >
            <component :is="icon" class="size-[17px]" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-2">
                <p
                    class="text-[13px] leading-snug font-emphasis text-foreground"
                >
                    {{ notice.title }}
                </p>
                <span
                    class="shrink-0 pt-px text-[11px] whitespace-nowrap text-subtle-foreground"
                    >{{ when }}</span
                >
            </div>
            <p
                v-if="notice.body"
                class="mt-0.5 line-clamp-2 text-[12.5px] leading-snug break-words text-muted-foreground"
            >
                {{ notice.body }}
            </p>
            <div
                v-if="notice.actions.length"
                class="mt-1.5 flex flex-wrap gap-1.5"
            >
                <template
                    v-for="action in notice.actions"
                    :key="actionKey(action)"
                >
                    <a
                        v-if="action.external"
                        :href="action.href"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="actionClass"
                        :data-testid="`notice-action-${notice.id}`"
                        @click="act"
                        >{{ action.label }}</a
                    >
                    <Link
                        v-else
                        :href="action.href"
                        :class="actionClass"
                        :data-testid="`notice-action-${notice.id}`"
                        @click="act"
                        >{{ action.label }}</Link
                    >
                </template>
            </div>
        </div>
        <span
            v-if="!notice.read"
            class="mt-2 size-2 shrink-0 rounded-full bg-primary-strong"
            aria-hidden="true"
        />
    </div>
</template>
