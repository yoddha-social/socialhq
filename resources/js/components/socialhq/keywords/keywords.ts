/**
 * SEO keywords on Brand knowledge (Strategy tab): the props, the keyword
 * check's verdicts and the file import, read in the browser and saved
 * through the same PUT as every other change.
 *
 * clean() and sameKey() mirror BrandKeywords::clean() and sameKey() on the
 * server, which cleans and drops duplicates again on save.
 */

export interface Keyword {
    text: string;
    main: boolean;
}

export interface KeywordLimits {
    main: number;
    supporting: number;
    prompt: number;
}

/** BrandKnowledgeController's `keywords` prop. */
export interface KeywordsProp {
    items: Keyword[];
    limits: KeywordLimits;
}

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
    prompt: Keyword[];
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

/** One line, lowercased, a leading # dropped, runs of """ broken up. */
export const clean = (text: string): string =>
    text
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
const GROUPS = ['main', 'supporting'];

/**
 * A text file is one keyword per line (commas split too). A CSV takes a
 * "keyword" column (or "keywords", "phrase", "search term"), else the first
 * one, and an optional "group" column (or "type", "priority") that says main
 * or supporting.
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
    | 'over_limit';

export interface ImportRow {
    text: string;
    main: boolean;
    add: boolean;
    note: ImportNote;
}

/**
 * What importing a file would do to the list: each row is added (main while
 * main slots are free) or skipped with the reason.
 */
export const planImport = (
    parsed: ParsedFile,
    existing: Keyword[],
    limits: KeywordLimits,
): ImportRow[] => {
    const seen = new Set(existing.map((keyword) => sameKey(keyword.text)));
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

        if (key === '' || seen.has(key)) {
            return skip('duplicate');
        }

        const main = row.wantsMain && mainFree > 0;

        if (!main && supportingFree <= 0) {
            return skip('over_limit');
        }

        seen.add(key);

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
    'pashmina shawl,supporting',
    'gift ideas nepal,supporting',
    'thamel shopping,supporting',
].join('\n');
