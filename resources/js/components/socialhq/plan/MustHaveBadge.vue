<script setup lang="ts">
import { IconPinned } from '@tabler/icons-vue';
import type { HTMLAttributes } from 'vue';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <!-- A post the team required in "Generate with AI": the app made sure the plan has it. -->
    <Badge
        variant="outline"
        :class="
            cn(
                'border-primary/40 bg-primary-subtle px-1.5 text-primary-text',
                props.class,
            )
        "
    >
        <IconPinned aria-hidden="true" />
        {{ $t('socialhq_plan.item.must_have') }}
    </Badge>
</template>
