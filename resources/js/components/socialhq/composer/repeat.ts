import type { RecurrenceFrequency, RecurrenceRule } from '@/lib/recurrence';
import {
    MAX_RECURRENCE_INTERVAL,
    MAX_RECURRENCE_TIMES,
    RECURRENCE_FREQUENCIES,
} from '@/lib/recurrence';

/** A whole number from 1 to `max`, as the repeat's interval and count must be. */
export const isWithinLimit = (value: unknown, max: number): value is number =>
    Number.isInteger(value) &&
    (value as number) >= 1 &&
    (value as number) <= max;

/**
 * A repeat read back from the browser's storage (the unfinished post), or
 * null when what is stored is not one.
 */
export const repeatRuleFrom = (value: unknown): RecurrenceRule | null => {
    if (!value || typeof value !== 'object') return null;

    const { interval, frequency, times } = value as Record<string, unknown>;

    return isWithinLimit(interval, MAX_RECURRENCE_INTERVAL) &&
        isWithinLimit(times, MAX_RECURRENCE_TIMES) &&
        RECURRENCE_FREQUENCIES.includes(frequency as RecurrenceFrequency)
        ? { interval, frequency: frequency as RecurrenceFrequency, times }
        : null;
};
