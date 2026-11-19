<script setup lang="ts">
import { Link, router, useHttp, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { IconBell } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import NoticeRow from '@/components/socialhq/notices/NoticeRow.vue';
import type {
    NoticeItem,
    NoticeList,
    NoticeShare,
} from '@/components/socialhq/notices/notices';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import dayjs from '@/dayjs';
import { preferences } from '@/routes/app/notifications';
import { index, read } from '@/routes/socialhq/notices';

const props = defineProps<{
    share: NoticeShare;
}>();

const page = usePage();

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const notices = ref<NoticeItem[] | null>(null);
const unread = ref(props.share.unread);

watch(
    () => props.share.unread,
    (value) => {
        unread.value = value;
    },
);

const listHttp = useHttp<Record<string, never>, NoticeList>({});
const readHttp = useHttp<{ ids?: number[] }, { unread: number }>({});

const load = async (): Promise<NoticeList | null> => {
    loading.value = true;
    failed.value = false;

    try {
        const list = await listHttp.get(index.url());

        notices.value = list.notices;
        unread.value = list.unread;

        return list;
    } catch {
        failed.value = true;

        return null;
    } finally {
        loading.value = false;
    }
};

const markRead = async (ids?: number[]): Promise<void> => {
    const wanted = (notice: NoticeItem): boolean =>
        ids === undefined || ids.includes(notice.id);

    notices.value =
        notices.value?.map((notice) =>
            wanted(notice) ? { ...notice, read: true } : notice,
        ) ?? null;

    try {
        const answer = await readHttp
            .transform(() => (ids === undefined ? {} : { ids }))
            .post(read.url());

        unread.value = answer.unread;
    } catch {
        // The count is right again on the next page or the next time the bell opens.
    }
};

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

/**
 * A notice written while the app is open: the count goes up, and a tab the
 * person is looking at shows it in the corner, which counts as seen.
 */
const currentWorkspaceId = computed(
    () =>
        (page.props.auth as { user?: { current_workspace_id?: string } })?.user
            ?.current_workspace_id ?? null,
);

const showLive = async (id: number): Promise<void> => {
    const list = await load();
    const notice = list?.notices.find((item) => item.id === id);

    if (!notice || document.visibilityState !== 'visible' || open.value) {
        return;
    }

    const action = notice.actions[0];

    toast(notice.title, {
        description: notice.body || undefined,
        position: 'bottom-right',
        duration: 8000,
        action: action
            ? {
                  label: action.label,
                  onClick: () => {
                      if (action.external) {
                          window.open(action.href, '_blank', 'noopener');
                      } else {
                          router.visit(action.href);
                      }
                  },
              }
            : undefined,
    });

    void markRead([notice.id]);
};

useEcho<{ id: number; workspace_id: string }>(
    props.share.channel,
    '.notice.created',
    (payload) => {
        if (
            currentWorkspaceId.value !== null &&
            payload.workspace_id !== currentWorkspaceId.value
        ) {
            return;
        }

        unread.value += 1;
        void showLive(payload.id);
    },
);

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
