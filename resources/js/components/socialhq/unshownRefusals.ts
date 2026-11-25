import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';

/** Long enough for the visit's own onError and the re-render that shows its message. */
const SHOWN_CHECK_DELAY_MS = 150;

const isOnScreen = (message: string): boolean =>
    document.body.innerText.includes(message);

/**
 * The safety net for refusals no page shows (audit 2026-11-24, FE-01): a
 * server message attached to a key no field renders, or a rate limit, used
 * to look like a click that did nothing.
 *
 * - A visit that comes back with errors: once the page has had its turn
 *   (its own onError, the re-render), the first message that is nowhere on
 *   screen, a toast included, becomes a toast.
 * - A rate limit (429), which Laravel answers with a plain error page that
 *   Inertia would open in a modal: a toast instead, unless the visit
 *   handled it itself (its onHttpException returned false).
 *
 * Mounted once, in app.ts.
 */
export const toastUnshownRefusals = (): void => {
    router.on('error', (event) => {
        const messages = Object.values(event.detail.errors).filter(
            (message): message is string =>
                typeof message === 'string' && message.trim() !== '',
        );

        if (messages.length === 0) {
            return;
        }

        window.setTimeout(() => {
            const unshown = messages.find((message) => !isOnScreen(message));

            if (unshown) {
                toast.error(unshown);
            }
        }, SHOWN_CHECK_DELAY_MS);
    });

    router.on('httpException', (event) => {
        if (event.detail.response.status !== 429) {
            return;
        }

        toast.error(trans('socialhq.refusals.rate_limited'));

        return false;
    });
};
