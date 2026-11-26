import { router } from '@inertiajs/vue3';
import { onScopeDispose, ref } from 'vue';

/**
 * What the item page holds that isn't saved yet: the copy and brief forms, a
 * comment, a reviewer's note for the next upload (QA 2026-11-18, item 9). Each
 * part registers how it knows; the page asks before any of it is lost.
 */
const sources = new Set<() => boolean>();

export const trackUnsavedWork = (isDirty: () => boolean): void => {
    sources.add(isDirty);
    onScopeDispose(() => {
        sources.delete(isDirty);
    });
};

const hasUnsavedWork = (): boolean => [...sources].some((isDirty) => isDirty());

/**
 * Asks before leaving the item page with unsaved work: an Inertia visit to
 * another page gets our dialog, reloading or closing the tab gets the
 * browser's own prompt. Writes and partial reloads are never held. Unlike the
 * composer's guard it doesn't hold the browser's back button.
 */
export const useItemPageGuard = () => {
    const confirming = ref(false);
    let pending: (() => void) | null = null;
    let leaving = false;

    const stopVisits = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (
            leaving ||
            visit.method !== 'get' ||
            visit.only.length > 0 ||
            visit.except.length > 0 ||
            visit.prefetch ||
            visit.url.href === window.location.href ||
            !hasUnsavedWork()
        ) {
            return;
        }

        event.preventDefault();
        pending = () =>
            router.visit(visit.url.href, {
                preserveScroll: visit.preserveScroll,
                preserveState: visit.preserveState,
                replace: visit.replace,
                // A visit that didn't land (cancelled, failed) keeps guarding.
                onFinish: (finished) => {
                    if (!finished.completed) {
                        leaving = false;
                    }
                },
            });
        confirming.value = true;
    });

    const onBeforeUnload = (event: BeforeUnloadEvent): void => {
        if (leaving || !hasUnsavedWork()) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    };

    window.addEventListener('beforeunload', onBeforeUnload);

    onScopeDispose(() => {
        stopVisits();
        window.removeEventListener('beforeunload', onBeforeUnload);
    });

    const discard = (): void => {
        const action = pending;
        pending = null;
        confirming.value = false;

        if (action) {
            leaving = true;
            action();
        }
    };

    const keepEditing = (): void => {
        pending = null;
        confirming.value = false;
    };

    return { confirming, discard, keepEditing };
};
