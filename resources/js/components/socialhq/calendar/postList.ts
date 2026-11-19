import { PostStatus } from '@/types/post';
import type { CalendarPost } from '@/types/publish';

export type CalendarListTab = 'scheduled' | 'drafts' | 'sent';

export const CALENDAR_LIST_TABS: readonly CalendarListTab[] = [
    'scheduled',
    'drafts',
    'sent',
];

const TAB_KEY = 'socialhq:calendar:list-tab';
const OPEN_KEY = 'socialhq:calendar:list-open';

/**
 * Which tab of the calendar's post list a post belongs to. A failed post stays
 * under Scheduled: it still needs someone, like a post waiting for approval.
 */
export const calendarListTab = (post: CalendarPost): CalendarListTab => {
    if (post.status === PostStatus.Draft) {
        return 'drafts';
    }

    return post.status === PostStatus.Published ? 'sent' : 'scheduled';
};

const read = (key: string): string | null => {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
};

const write = (key: string, value: string): void => {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // A browser that refuses storage only forgets the choice.
    }
};

/** The tab this browser last had open; Drafts for a first visit. */
export const rememberedListTab = (): CalendarListTab => {
    const stored = read(TAB_KEY);

    return CALENDAR_LIST_TABS.includes(stored as CalendarListTab)
        ? (stored as CalendarListTab)
        : 'drafts';
};

export const rememberListTab = (tab: CalendarListTab): void => {
    write(TAB_KEY, tab);
};

export const rememberedListOpen = (): boolean => read(OPEN_KEY) === '1';

export const rememberListOpen = (open: boolean): void => {
    write(OPEN_KEY, open ? '1' : '0');
};
