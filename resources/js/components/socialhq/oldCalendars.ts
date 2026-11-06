// Social HQ: old content calendars imported into brand knowledge.

export const CALENDAR_FIELDS = [
    'date',
    'channel',
    'format',
    'title',
    'caption',
    'pillar',
    'notes',
    'engagement',
    'reach',
    'views',
    'link',
] as const;

export type CalendarField = (typeof CALENDAR_FIELDS)[number];

export type CalendarMapping = Record<CalendarField, number | null>;

export type DateOrder = 'day_first' | 'month_first';

export type SkipReason =
    | 'empty'
    | 'no_date'
    | 'unreadable_date'
    | 'bikram_sambat'
    | 'future_date';

export interface SkippedRow {
    row: number;
    reason: SkipReason;
    value: string | null;
}

/** An imported calendar, as the Brand knowledge page lists it. */
export interface PastCalendar {
    id: number;
    file_name: string;
    rows_imported: number;
    rows_skipped: number;
    skipped_reasons: SkippedRow[];
    first_date: string | null;
    last_date: string | null;
    created_by: string | null;
    created_at: string | null;
}

export interface CalendarColumn {
    index: number;
    label: string;
    letter: string;
}

/** One sheet of an uploaded file, as the mapping step shows it. */
export interface CalendarSheetPreview {
    index: number;
    name: string;
    header_row: number;
    rows: number;
    columns: CalendarColumn[];
    samples: string[][];
    mapping: CalendarMapping;
    date_order: DateOrder;
    date_order_certain: boolean;
}

export interface CalendarUpload {
    id: number;
    file_name: string;
    sheets: CalendarSheetPreview[];
}

export interface PastCalendarRow {
    id: number;
    row: number;
    date: string;
    platform: string | null;
    channel: string | null;
    format: string | null;
    title: string | null;
    excerpt: string | null;
    pillar: string | null;
    engagement: number | null;
    reach: number | null;
    views: number | null;
    link: string | null;
}
