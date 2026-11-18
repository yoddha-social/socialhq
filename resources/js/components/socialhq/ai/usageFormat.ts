import type {
    UsageLogRow,
    UsageStatus,
} from '@/components/socialhq/ai/usageTypes';
import { activeLocale } from '@/language';

/** Dollars, with more digits for amounts under a cent so they don't read as $0.00. */
export const formatCost = (value: number): string =>
    new Intl.NumberFormat(activeLocale.value, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: value > 0 && value < 0.01 ? 6 : 2,
    }).format(value);

export const formatDuration = (ms: number): string =>
    ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`;

export type UsageStatusVariant =
    | 'success'
    | 'destructive-subtle'
    | 'warning'
    | 'secondary';

export const statusVariant = (status: UsageStatus): UsageStatusVariant => {
    if (status === 'ok') {
        return 'success';
    }

    if (status === 'failed') {
        return 'destructive-subtle';
    }

    return status === 'rate_limited' ? 'secondary' : 'warning';
};

/** "groq · llama-3.3-70b", or whichever half is known. */
export const answeredBy = (row: UsageLogRow): string | null =>
    row.provider && row.model
        ? `${row.provider} · ${row.model}`
        : (row.model ?? row.provider);

/** The lang key explaining a failed, blocked or fallen-back request; null for a plain answer. */
export const explanation = (row: UsageLogRow): string | null => {
    if (row.status !== 'ok') {
        return `socialhq_ai_usage.explain.${row.status}`;
    }

    return row.fallbacks > 0 ? 'socialhq_ai_usage.explain.fell_back' : null;
};

/** Prompt plus completion tokens; null when the provider reported neither. */
export const rowTokens = (row: UsageLogRow): number | null =>
    row.prompt_tokens === null && row.completion_tokens === null
        ? null
        : (row.prompt_tokens ?? 0) + (row.completion_tokens ?? 0);

/** A share as a whole percent for a bar's width, 0 when there is nothing to share. */
export const percentOf = (part: number, whole: number): number =>
    whole > 0 ? Math.min(100, Math.round((part / whole) * 100)) : 0;

/** Column layout shared by a day's heading row and its rows (md and up; phones stack). */
export const USAGE_ROW_GRID =
    'md:grid md:grid-cols-[4.5rem_minmax(0,1.1fr)_minmax(0,0.9fr)_minmax(0,1.4fr)_4.5rem_4.5rem_5.5rem_8rem_1rem] md:items-center md:gap-3';
