<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconBell } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';

import NoticeRow from '@/components/socialhq/notices/NoticeRow.vue';
import type { NoticeItem } from '@/components/socialhq/notices/notices';
import {
    noticeState,
    useNotices,
} from '@/components/socialhq/notices/useNotices';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import dayjs from '@/dayjs';
import { preferences } from '@/routes/app/notifications';

const { load, markRead } = useNotices();

const open = computed({
    get: () => noticeState.panelOpen,
    set: (value: boolean) => {
        noticeState.panelOpen = value;
    },
});

const unread = computed(() => noticeState.unread);
const notices = computed(() => noticeState.notices);
const loading = computed(() => noticeState.loading);
const failed = computed(() => noticeState.failed);

const markAllRead = (): void => {
    void markRead();
};

const act = (notice: NoticeItem): void => {
    open.value = false;

    if (!notice.read) {
        void markRead([notice.id]);
    }
};

watch(open, (isOpen) => {
    if (isOpen) {
        void load();
    }
});

const isToday = (notice: NoticeItem): boolean =>
    dayjs(notice.created_at).isSame(dayjs(), 'day');

const groups = computed(() =>
    [
        {
            key: 'today',
            items: (notices.value ?? []).filter(isToday),
        },
        {
            key: 'earlier',
            items: (notices.value ?? []).filter((notice) => !isToday(notice)),
        },
    ].filter((group) => group.items.length > 0),
);

const badge = computed(() => (unread.value > 99 ? '99+' : unread.value));

const label = computed(() =>
    unread.value > 0
        ? `${trans('socialhq.notices.open')} (${trans('socialhq.notices.unread', { count: String(unread.value) })})`
        : trans('socialhq.notices.open'),
);
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="relative inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-border-strong bg-card text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[state=open]:bg-accent"
                :aria-label="label"
                data-testid="notice-bell"
            >
                <IconBell class="size-[18px]" aria-hidden="true" />
                <span
                    v-if="unread > 0"
                    class="absolute -end-1.5 -top-1.5 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-destructive px-1 text-[10.5px] leading-none font-semibold text-white"
                    data-testid="notice-bell-count"
                    >{{ badge }}</span
                >
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            :side-offset="8"
            class="w-[400px] max-w-[calc(100vw-1rem)] rounded-xl p-0 shadow-xl"
            data-testid="notice-panel"
            @open-auto-focus.prevent
        >
            <div
                class="flex items-center justify-between gap-2 border-b border-border px-4 py-3"
            >
                <p class="font-heading text-[15px] font-medium text-foreground">
                    {{ $t('socialhq.notices.title') }}
                </p>
                <button
                    v-if="unread > 0"
                    type="button"
                    class="text-[12.5px] font-medium text-primary-strong hover:underline"
                    data-testid="notice-mark-all-read"
                    @click="markAllRead"
                >
                    {{ $t('socialhq.notices.mark_all_read') }}
                </button>
            </div>

            <div class="max-h-[min(70vh,520px)] overflow-y-auto p-2">
                <div
                    v-if="notices === null && loading"
                    class="flex flex-col gap-2 p-1"
                    aria-hidden="true"
                >
                    <div
                        v-for="row in 3"
                        :key="row"
                        class="h-16 animate-pulse rounded-lg bg-secondary"
                    />
                </div>
                <p
                    v-else-if="notices === null && failed"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                    data-testid="notice-load-failed"
                >
                    {{ $t('socialhq.notices.load_failed') }}
                </p>
                <div
                    v-else-if="notices !== null && notices.length === 0"
                    class="px-4 py-8 text-center"
                    data-testid="notice-empty"
                >
                    <p class="text-sm font-emphasis text-foreground">
                        {{ $t('socialhq.notices.empty_title') }}
                    </p>
                    <p class="mt-1 text-[13px] text-muted-foreground">
                        {{ $t('socialhq.notices.empty_body') }}
                    </p>
                </div>
                <template v-else>
                    <template v-for="group in groups" :key="group.key">
                        <p
                            class="px-3 pt-2 pb-1 text-[11.5px] font-medium text-subtle-foreground"
                        >
                            {{ $t(`socialhq.notices.${group.key}`) }}
                        </p>
                        <NoticeRow
                            v-for="notice in group.items"
                            :key="notice.id"
                            :notice="notice"
                            @act="act"
                        />
                    </template>
                </template>
            </div>

            <div
                class="flex items-center justify-between gap-2 border-t border-border px-4 py-2.5 text-[12px] text-subtle-foreground"
            >
                <span>{{ $t('socialhq.notices.kept') }}</span>
                <Link
                    :href="preferences.url()"
                    class="font-medium text-primary-strong hover:underline"
                    data-testid="notice-settings"
                    >{{ $t('socialhq.notices.settings') }}</Link
                >
            </div>
        </PopoverContent>
    </Popover>
</template>
