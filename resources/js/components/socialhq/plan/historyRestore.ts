import { router } from '@inertiajs/vue3';

let restored = false;

// Back and Forward show a page with the props it had then, without asking the
// server; any visit the app starts clears the mark.
window.addEventListener('popstate', () => {
    restored = true;
});
router.on('start', () => {
    restored = false;
});

/**
 * Whether the page just shown came back from the browser's history, so its
 * props may be old (QA 2026-11-18, item 8). Reading it clears it.
 */
export const takeHistoryRestore = (): boolean => {
    const wasRestored = restored;
    restored = false;

    return wasRestored;
};
