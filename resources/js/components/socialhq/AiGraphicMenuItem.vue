<script setup lang="ts">
import { IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

import { useAiGraphicsOffer } from '@/components/socialhq/aiGraphics';
import AiMissingTooltip from '@/components/socialhq/AiMissingTooltip.vue';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';

// Social HQ: "Generate with AI" in the media menu. It shows while the
// company has some AI and the user may create posts here; when that AI
// can't make graphics, it stays in the menu, dimmed, and says what's missing.

const emit = defineEmits<{ select: [] }>();

const { shown, missingKey } = useAiGraphicsOffer();

const choose = (): void => {
    if (missingKey.value) {
        toast.error(trans(missingKey.value));

        return;
    }

    emit('select');
};
</script>

<template>
    <AiMissingTooltip
        v-if="shown"
        :message="missingKey ? $t(missingKey) : null"
        side="right"
    >
        <DropdownMenuItem
            data-testid="media-source-socialhq-ai"
            :aria-disabled="missingKey ? 'true' : undefined"
            :class="
                missingKey
                    ? 'text-muted-foreground focus:text-muted-foreground'
                    : ''
            "
            @select="choose"
        >
            <IconSparkles
                :class="
                    missingKey ? 'text-muted-foreground' : 'text-foreground'
                "
            />
            {{ $t('socialhq.ai_graphics.menu_item') }}
        </DropdownMenuItem>
    </AiMissingTooltip>
</template>
