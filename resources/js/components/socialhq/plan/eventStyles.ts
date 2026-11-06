/**
 * Event categories and their colours, defined once for the events page and
 * the markers on the publish calendar. An unconfirmed date always draws with
 * a dashed outline on top of its category colour.
 */
export type EventCategory =
    | 'festival'
    | 'public_holiday'
    | 'national_day'
    | 'international_day'
    | 'commercial'
    | 'observance'
    | 'brand';

export type EventRegion = 'nepal' | 'international' | 'brand';

export interface CalendarEventRow {
    id: number;
    name: string;
    name_ne: string | null;
    category: EventCategory;
    region: EventRegion;
    starts_on: string;
    ends_on: string;
    bs_date: string | null;
    public_holiday: boolean;
    description: string | null;
    source_url: string | null;
    source_name: string | null;
    brand_only: boolean;
    verified: boolean;
    verified_by: string | null;
    verified_at: string | null;
}

/** One event on a day of the publish calendar (`shqEvents` prop). */
export interface CalendarEventMarker {
    name: string;
    verified: boolean;
    category: EventCategory;
}

interface CategoryStyle {
    /** Badge fill and text, light and dark. */
    badge: string;
    /** A small solid dot, for markers and legends. */
    dot: string;
}

const STYLES: Record<EventCategory, CategoryStyle> = {
    festival: {
        badge: 'bg-orange-400/15 text-orange-700 dark:text-orange-300',
        dot: 'bg-orange-500',
    },
    public_holiday: {
        badge: 'bg-rose-400/15 text-rose-700 dark:text-rose-300',
        dot: 'bg-rose-500',
    },
    national_day: {
        badge: 'bg-sky-400/15 text-sky-700 dark:text-sky-300',
        dot: 'bg-sky-500',
    },
    international_day: {
        badge: 'bg-teal-400/15 text-teal-700 dark:text-teal-300',
        dot: 'bg-teal-500',
    },
    commercial: {
        badge: 'bg-violet-400/15 text-violet-700 dark:text-violet-300',
        dot: 'bg-violet-500',
    },
    observance: {
        badge: 'bg-slate-400/15 text-slate-700 dark:text-slate-300',
        dot: 'bg-slate-500',
    },
    brand: {
        badge: 'bg-pink-400/15 text-pink-700 dark:text-pink-300',
        dot: 'bg-pink-500',
    },
};

const styleOf = (category: string): CategoryStyle =>
    STYLES[category as EventCategory] ?? STYLES.observance;

export const categoryBadgeClass = (category: string): string =>
    styleOf(category).badge;

export const categoryDotClass = (category: string): string =>
    styleOf(category).dot;

/** The outline of an event that nobody has confirmed yet. */
export const NEEDS_CHECK_CLASS =
    'border border-dashed border-amber-500/70 bg-transparent text-amber-700 dark:text-amber-300';
