<script setup lang="ts">
import { IconCalendarStats } from '@tabler/icons-vue';

import HeaderTitle from '@/components/HeaderTitle.vue';
</script>

<template>
    <div data-testid="plan-header">
        <HeaderTitle
            :title="$t('socialhq_plan.nav')"
            :icon="IconCalendarStats"
        />
    </div>
</template>
