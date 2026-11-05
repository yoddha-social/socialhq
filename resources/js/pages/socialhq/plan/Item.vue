<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import PlanHeader from '@/components/socialhq/plan/PlanHeader.vue';
import PlanTabs from '@/components/socialhq/plan/PlanTabs.vue';
import AppLayout from '@/layouts/AppLayout.vue';
</script>

<template>
    <Head :title="$t('socialhq_plan.tabs.plan')" />
    <AppLayout full-width>
        <template #header>
            <PlanHeader />
        </template>
        <PlanTabs active="plan" />
    </AppLayout>
</template>
