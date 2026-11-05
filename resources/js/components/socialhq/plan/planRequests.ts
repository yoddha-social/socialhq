import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

/**
 * Feedback for the plan's quick writes (accept, drop, restore, review):
 * the first validation message, or a generic failure for any other refusal.
 */
export const toastFirstError = (errors: Record<string, string>): void => {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
};

export const toastRequestFailed = (): false => {
    toast.error(trans('socialhq_plan.errors.request_failed'));

    return false;
};
