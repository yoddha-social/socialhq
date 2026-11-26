/**
 * SEO keywords on Brand knowledge (Strategy tab): the props, the keyword
 * check's verdicts and the file import, read in the browser and saved
 * through the same PUT as every other change.
 *
 * clean() and sameKey() mirror BrandKeywords::clean() and sameKey() on the
 * server, which cleans and drops duplicates again on save.
 */

export type KeywordTagKind = 'pillar' | 'product' | 'event' | 'months';

/**
 * A keyword's tag (BrandKeywords::presentTag()): a pillar by name, a product
 * or service by its knowledge entry, an event by its occasion key (the same
 * every year), or a range of months. `label` is what the page shows.
 */
export interface KeywordTag {
    kind: KeywordTagKind;
    value: string | null;
    entry_id: number | null;
    from: number | null;
    to: number | null;
    label: string;
}

export interface Keyword {
    text: string;
    main: boolean;
    tags: KeywordTag[];
}

export interface KeywordLimits {
    main: number;
    supporting: number;
    /** Tags on one keyword. */
    tags: number;
    /** Library keywords one post gets. */
    per_post: number;
}

/** One of the brand's dates to tag keywords with (Occasion::choices()). */
export interface KeywordOccasion {
    key: string;
    name: string;
    starts_on: string;
}

/** BrandKnowledgeController's `keywords` prop. */
export interface KeywordsProp {
    items: Keyword[];
    limits: KeywordLimits;
    occasions: KeywordOccasion[];
    occasion_days: { before: number; after: number };
}

/** A tag as one post's keywords carry it. */
export interface ShortTag {
    kind: KeywordTagKind;
    label: string;
}

/** A keyword picked for one post (KeywordPicker): `matched` are the tags that fit it. */
export interface PickedKeyword {
    text: string;
    main: boolean;
    tags: ShortTag[];
    matched: ShortTag[];
}

/** "Keywords for this post" (KeywordPicker::panel()). */
export interface KeywordPanel {
    /** What goes to the AI: the person's choice, else the app's pick. */
    picked: PickedKeyword[];
    app_pick: PickedKeyword[];
    /** Whether a person changed them for this post. */
    chosen: boolean;
    catalogue: { text: string; main: boolean; tags: ShortTag[] }[];
    max: number;
}

/** What identifies a tag, for filters and for spotting the same tag twice. */
export const tagKey = (tag: KeywordTag): string => {
    switch (tag.kind) {
        case 'pillar':
            return `pillar:${(tag.value ?? '').toLowerCase()}`;
        case 'product':
            return `product:${tag.entry_id}`;
        case 'event':
            return `event:${tag.value}`;
        default:
            return `months:${tag.from}-${tag.to}`;
    }
};

export type KeywordVerdict = 'good' | 'check' | 'weak' | 'conflict';

export interface KeywordFound {
    kind: 'profile' | 'entry' | 'document';
    title: string;
    label: string;
    snippet: string;
}

/** One keyword's check (KeywordCheck::check()). */
export interface KeywordCheckRow {
    text: string;
    main: boolean;
    verdict: KeywordVerdict;
    reason: string;
    fix: string | null;
    found: KeywordFound[];
    posts: number;
    /** On the list for longer than the health days, and no post of those days says it. */
    stale: boolean;
}

/** BrandKnowledgeController's deferred `keywordCheck` prop (KeywordCheck::page()). */
export interface KeywordCheckProp {
    keywords: KeywordCheckRow[];
    counts: Record<KeywordVerdict, number>;
    verdict: 'on_brand' | 'mostly' | 'needs_look' | null;
    checked: {
        entries: number;
        documents: number;
        posts: number;
        days: number;
    };
    stale: { count: number; days: number };
    /** What a post going out today with no pillar or product gets. */
    prompt: PickedKeyword[];
}

/**
 * What an AI answer says about keywords (BrandKeywords::forAnswer()): the
 * note when the brand has none, else the keywords the text says (null when
 * there is no line to show).
 */
export interface KeywordAnswer {
    keywords_missing?: boolean;
    can_add_keywords?: boolean;
    keywords_used?: string[] | null;
    main_in_first_line?: boolean;
}

/** The verdicts in the order the counts bar shows them. */
export const VERDICTS: readonly KeywordVerdict[] = [
    'good',
    'check',
    'weak',
    'conflict',
];

export const MIN_LENGTH = 2;
export const MAX_LENGTH = 60;

/** Files the import reads: a keyword list is far smaller than this. */
export const MAX_FILE_BYTES = 100 * 1024;

/**
 * One line, lowercased, commas and semicolons made spaces (they separate
 * keywords, so a quoted CSV cell can't keep one), a leading # dropped, runs
 * of """ broken up.
 */
export const clean = (text: string): string =>
    text
        .replace(/[,;]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase()
        .replace(/^#+/, '')
        .replace(/"{3,}/g, (quotes) => quotes.split('').join(' '))
        .replace(/\s+/g, ' ')
        .trim();

/** Two keywords are the same when their words are, case and accents ignored. */
export const sameKey = (text: string): string =>
    (
        text
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .normalize('NFC')
            .match(/[\p{L}\p{M}\p{N}]+/gu) ?? []
    ).join(' ');

/** A pasted list: one per line or separated by commas. */
export const splitList = (raw: string): string[] =>
    raw
        .split(/[\n,]+/)
        .map(clean)
        .filter((text) => text !== '');

export interface ParsedFile {
    /** Whether the file said which group each keyword is in. */
    hasGroupColumn: boolean;
    rows: { text: string; wantsMain: boolean }[];
}

/** One CSV line's cells; quotes may hold commas. */
const cells = (line: string): string[] => {
    const out: string[] = [];
    let current = '';
    let quoted = false;

    for (const character of line) {
        if (character === '"') {
            quoted = !quoted;
        } else if ((character === ',' || character === ';') && !quoted) {
            out.push(current.trim());
            current = '';
        } else {
            current += character;
        }
    }

    out.push(current.trim());

    return out;
};

const KEYWORD_COLUMNS = ['keyword', 'keywords', 'phrase', 'search term'];
const GROUP_COLUMNS = ['group', 'type', 'priority'];
const GROUPS = ['main', 'supporting', 'library'];

/**
 * A text file is one keyword per line (commas split too). A CSV takes a
 * "keyword" column (or "keywords", "phrase", "search term"), else the first
 * one, and an optional "group" column (or "type", "priority") that says main
 * or library ("supporting" still reads as library).
 */
export const parseKeywordFile = (name: string, text: string): ParsedFile => {
    const lines = text
        .replace(/^﻿/, '')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '');

    if (name.toLowerCase().endsWith('.txt')) {
        return {
            hasGroupColumn: false,
            rows: lines
                .flatMap((line) => line.split(','))
                .map((cell) => ({ text: clean(cell), wantsMain: false })),
        };
    }

    let rows = lines.map(cells);
    const header = (rows[0] ?? []).map((cell) => cell.toLowerCase());
    let keywordColumn = 0;
    let groupColumn = -1;

    if (header.some((cell) => KEYWORD_COLUMNS.includes(cell))) {
        keywordColumn = header.findIndex((cell) =>
            KEYWORD_COLUMNS.includes(cell),
        );
        groupColumn = header.findIndex((cell) => GROUP_COLUMNS.includes(cell));
        rows = rows.slice(1);
    } else if (
        rows.some((row) => GROUPS.includes((row[1] ?? '').toLowerCase()))
    ) {
        groupColumn = 1;
    }

    return {
        hasGroupColumn: groupColumn >= 0,
        rows: rows.map((row) => ({
            text: clean(row[keywordColumn] ?? ''),
            wantsMain:
                groupColumn >= 0 &&
                (row[groupColumn] ?? '').toLowerCase() === 'main',
        })),
    };
};

export type ImportNote =
    | 'main'
    | 'supporting'
    | 'main_full'
    | 'too_short'
    | 'too_long'
    | 'duplicate'
    | 'repeated'
    | 'over_limit';

export interface ImportRow {
    text: string;
    main: boolean;
    add: boolean;
    note: ImportNote;
}

/**
 * What importing a file would do to the list: each row is added (main while
 * main slots are free) or skipped with the reason: already a keyword
 * (duplicate), or a second time in the file (repeated).
 */
export const planImport = (
    parsed: ParsedFile,
    existing: Keyword[],
    limits: KeywordLimits,
): ImportRow[] => {
    const saved = new Set(existing.map((keyword) => sameKey(keyword.text)));
    const inFile = new Set<string>();
    let mainFree =
        limits.main - existing.filter((keyword) => keyword.main).length;
    let supportingFree =
        limits.supporting - existing.filter((keyword) => !keyword.main).length;

    return parsed.rows.map((row): ImportRow => {
        const skip = (note: ImportNote): ImportRow => ({
            text: row.text,
            main: false,
            add: false,
            note,
        });

        if (row.text.length < MIN_LENGTH) {
            return skip('too_short');
        }

        if (row.text.length > MAX_LENGTH) {
            return skip('too_long');
        }

        const key = sameKey(row.text);

        if (key === '' || saved.has(key)) {
            return skip('duplicate');
        }

        if (inFile.has(key)) {
            return skip('repeated');
        }

        const main = row.wantsMain && mainFree > 0;

        if (!main && supportingFree <= 0) {
            return skip('over_limit');
        }

        inFile.add(key);

        if (main) {
            mainFree--;
        } else {
            supportingFree--;
        }

        return {
            text: row.text,
            main,
            add: true,
            note: main ? 'main' : row.wantsMain ? 'main_full' : 'supporting',
        };
    });
};

/** The sample file offered in the empty box. */
export const SAMPLE_FILE = [
    'keyword,group',
    'handmade pashmina kathmandu,main',
    'nepali tea gifts,main',
    'pashmina shawl,library',
    'gift ideas nepal,library',
    'thamel shopping,library',
].join('\n');
