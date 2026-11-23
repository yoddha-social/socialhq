import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

/** The AI tasks the shared `aiTasks` prop reports (HandleInertiaRequests). */
export type AiTaskName = 'write' | 'structured' | 'vision';

type AiTasksProp = Partial<Record<AiTaskName | 'any', boolean>> | undefined;

/**
 * Whether the company can use an AI task now, so an AI button hides
 * instead of failing on every click. Without the prop the button shows and
 * the server's answer says why it can't run.
 */
export const useAiTask = (task: AiTaskName): ComputedRef<boolean> => {
    const page = usePage();

    return computed(() => {
        const tasks = page.props.aiTasks as AiTasksProp;

        return tasks?.[task] !== false;
    });
};

/**
 * - `ready`: the task can run.
 * - `missing`: the company has AI, but none that serves the task; the
 *   button shows, can't be used, and says what is missing.
 * - `off`: the company has no AI at all; the button hides.
 */
export type AiTaskState = 'ready' | 'missing' | 'off';

/**
 * What an AI button whose task the company may lack does: run, say what is
 * missing, or hide. Without the prop the button shows and the server's
 * answer says why it can't run.
 */
export const useAiTaskState = (task: AiTaskName): ComputedRef<AiTaskState> => {
    const page = usePage();

    return computed(() => {
        const tasks = page.props.aiTasks as AiTasksProp;

        if (tasks?.[task] !== false) {
            return 'ready';
        }

        return tasks?.any === true ? 'missing' : 'off';
    });
};

/**
 * Who a "your company's AI can't do this" message is for: the owner, who
 * adds providers in Settings › AI, or someone who asks the owner. Keys
 * under `.owner` and `.member` follow it.
 */
export const useAiSetupAudience = (): ComputedRef<'owner' | 'member'> => {
    const page = usePage();

    return computed(() =>
        page.props.socialhq?.companyAi?.canManage ? 'owner' : 'member',
    );
};
