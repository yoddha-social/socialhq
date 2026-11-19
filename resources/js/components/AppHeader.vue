<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import NoticeBell from '@/components/socialhq/notices/NoticeBell.vue';
import type { NoticeShare } from '@/components/socialhq/notices/notices';

// Social HQ: the notification bell ends the header (PATCHES.md row 229).
const page = usePage();

const notices = computed(
    () =>
        (page.props.socialhq as { notices?: NoticeShare | null } | undefined)
            ?.notices ?? null,
);
</script>

<template>
    <header
        class="flex shrink-0 items-center justify-between gap-2 bg-card px-4 pt-4 md:px-8 md:pt-6"
        data-testid="app-header"
    >
        <div class="flex h-12 min-w-0 flex-1 items-center gap-2">
            <slot name="left" />
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <div id="app-header-actions" class="contents" />
            <slot name="right" />
            <NoticeBell v-if="notices" :share="notices" />
        </div>
    </header>
</template>
