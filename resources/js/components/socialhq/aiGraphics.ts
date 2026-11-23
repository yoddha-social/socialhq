import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

import { useAiSetupAudience } from '@/components/socialhq/aiTasks';

/**
 * "Generate with AI": whether it shows (the company has some AI and the
 * person may create posts), and, while it shows but can't work, the lang
 * key of what the company's AI lacks (`socialhq.ai_graphics.missing.*`).
 * Resolve the key with `$t` in the template; `trans()` in a click handler.
 */
export const useAiGraphicsOffer = (): {
    shown: ComputedRef<boolean>;
    missingKey: ComputedRef<string | null>;
} => {
    const page = usePage();
    const audience = useAiSetupAudience();
    const graphics = computed(() => page.props.socialhq.aiGraphics);

    return {
        shown: computed(() => graphics.value.offered),
        missingKey: computed(() =>
            !graphics.value.available && graphics.value.missing
                ? `socialhq.ai_graphics.missing.${graphics.value.missing}.${audience.value}`
                : null,
        ),
    };
};
