import { trans } from 'laravel-vue-i18n';
import { reactive } from 'vue';

/**
 * "Publish now" asks first (QA 2026-11-18 P1-3): on a real channel one
 * misclick posted at once. Every publish-now path awaits confirmPublishNow();
 * PublishNowConfirmDialog, mounted once in the app layout, answers it.
 */
export const publishNowConfirm = reactive({
    open: false,
    /** How many posts go out (the composer can send one per channel). */
    count: 1,
    mounted: 0,
    /** socialhq.publishNow.confirm, set by the dialog from the shared props. */
    enabled: true,
    resolve: null as ((confirmed: boolean) => void) | null,
});

export const confirmPublishNow = (count = 1): Promise<boolean> => {
    if (!publishNowConfirm.enabled) {
        return Promise.resolve(true);
    }

    // A page outside the app layout has no dialog: the browser's own box still asks.
    if (publishNowConfirm.mounted === 0) {
        return Promise.resolve(
            window.confirm(
                `${trans('socialhq.publish_now.title')}\n\n${trans('socialhq.publish_now.description')}`,
            ),
        );
    }

    publishNowConfirm.resolve?.(false);

    return new Promise((resolve) => {
        publishNowConfirm.count = count;
        publishNowConfirm.resolve = resolve;
        publishNowConfirm.open = true;
    });
};

export const answerPublishNow = (confirmed: boolean): void => {
    const resolve = publishNowConfirm.resolve;

    publishNowConfirm.resolve = null;
    publishNowConfirm.open = false;
    resolve?.(confirmed);
};
