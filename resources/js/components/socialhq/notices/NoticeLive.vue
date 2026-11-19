<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import { computed, watch } from 'vue';
import { toast } from 'vue-sonner';

import type { NoticeShare } from '@/components/socialhq/notices/notices';
import {
    noticeState,
    useNotices,
} from '@/components/socialhq/notices/useNotices';

const props = defineProps<{
    share: NoticeShare;
}>();

const page = usePage();
const { load, markRead } = useNotices();

watch(
    () => props.share.unread,
    (value) => {
        noticeState.unread = value;
    },
    { immediate: true },
);

const currentWorkspaceId = computed(
    () =>
        (page.props.auth as { user?: { current_workspace_id?: string } })?.user
            ?.current_workspace_id ?? null,
);

/** How long a live notice stays in the corner. */
const LIVE_NOTICE_MS = 8000;

/**
 * A notice written while the app is open: the count goes up, and a tab the
 * person is looking at shows it in the corner, which counts as seen.
 */
const showLive = async (id: number): Promise<void> => {
    const list = await load();
    const notice = list?.notices.find((item) => item.id === id);

    if (
        !notice ||
        notice.read ||
        document.visibilityState !== 'visible' ||
        noticeState.panelOpen
    ) {
        return;
    }

    const action = notice.actions[0];

    toast(notice.title, {
        description: notice.body || undefined,
        position: 'bottom-right',
        duration: LIVE_NOTICE_MS,
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

        noticeState.unread += 1;
        void showLive(payload.id);
    },
);
</script>

<template>
    <span hidden data-testid="notice-live" />
</template>
