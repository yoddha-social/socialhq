import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/** The AI tasks the shared `aiTasks` prop reports (HandleInertiaRequests). */
export type AiTaskName = 'write' | 'structured' | 'vision';

/**
 * Whether the company can use an AI task now, so an AI button hides
 * instead of failing on every click. Without the prop the button shows and
 * the server's answer says why it can't run.
 */
export const useAiTask = (task: AiTaskName): ComputedRef<boolean> => {
    const page = usePage();

    return computed(() => {
        const tasks = page.props.aiTasks as
            | Partial<Record<AiTaskName, boolean>>
            | undefined;

        return tasks?.[task] !== false;
    });
};
