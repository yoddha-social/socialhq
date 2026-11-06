<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconSparkles } from '@tabler/icons-vue';
import { computed } from 'vue';

import { DropdownMenuItem } from '@/components/ui/dropdown-menu';

// Social HQ: "Generate with AI" in the media menu, shown when an image
// provider is set up and the user may create posts here.

interface AiGraphicsProps {
    aiGraphics?: { available?: boolean };
}

const emit = defineEmits<{ select: [] }>();

const page = usePage();
const available = computed(() =>
    Boolean(
        (page.props.socialhq as AiGraphicsProps | undefined)?.aiGraphics
            ?.available,
    ),
);
</script>

<template>
    <DropdownMenuItem
        v-if="available"
        data-testid="media-source-socialhq-ai"
        @select="emit('select')"
    >
        <IconSparkles class="text-foreground" />
        {{ $t('socialhq.ai_graphics.menu_item') }}
    </DropdownMenuItem>
</template>
