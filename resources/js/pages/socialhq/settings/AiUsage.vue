<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import UsageLog from '@/components/socialhq/ai/UsageLog.vue';
import type { UsagePageProps } from '@/components/socialhq/ai/usageTypes';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { show } from '@/routes/socialhq/ai-usage';

defineProps<UsagePageProps>();
</script>

<template>
    <Head :title="$t('socialhq_ai_usage.page.title')" />

    <SettingsLayout
        :title="$t('socialhq_ai_usage.page.title')"
        :description="$t('socialhq_ai_usage.page.description')"
    >
        <UsageLog v-bind="$props" :url="show.url()" />
    </SettingsLayout>
</template>
