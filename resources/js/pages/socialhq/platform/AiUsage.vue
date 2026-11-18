<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import UsageLog from '@/components/socialhq/ai/UsageLog.vue';
import type {
    UsageDayQuery,
    UsagePageProps,
} from '@/components/socialhq/ai/usageTypes';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { day, index } from '@/routes/socialhq/platform/ai-usage';

defineProps<UsagePageProps>();

const dayUrl = (query: UsageDayQuery): string => day.url({ query });
</script>

<template>
    <Head :title="$t('socialhq_ai_usage.platform.title')" />

    <SettingsLayout
        wide
        :title="$t('socialhq_ai_usage.platform.title')"
        :description="$t('socialhq_ai_usage.platform.description')"
    >
        <UsageLog v-bind="$props" :url="index.url()" :day-url="dayUrl" />
    </SettingsLayout>
</template>
