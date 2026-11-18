<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import UsageLog from '@/components/socialhq/ai/UsageLog.vue';
import type {
    UsageDayQuery,
    UsagePageProps,
} from '@/components/socialhq/ai/usageTypes';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { day, show } from '@/routes/socialhq/ai-usage';

defineProps<UsagePageProps>();

const dayUrl = (query: UsageDayQuery): string => day.url({ query });
</script>

<template>
    <Head :title="$t('socialhq_ai_usage.page.title')" />

    <SettingsLayout
        wide
        :title="$t('socialhq_ai_usage.page.title')"
        :description="$t('socialhq_ai_usage.page.description')"
    >
        <UsageLog v-bind="$props" :url="show.url()" :day-url="dayUrl" />
    </SettingsLayout>
</template>
