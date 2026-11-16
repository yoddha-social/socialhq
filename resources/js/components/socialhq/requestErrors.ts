import { extractErrorMessage } from '@/lib/httpError';

/**
 * Reading a failed `useHttp` request. A 422 does not throw: the request
 * resolves with `undefined` and fills the form's `errors`, so callers check the
 * response first (`firstErrorOf`) and read the thrown error only for the rest.
 */

/** The first validation message of a `useHttp` form, or null. */
export const firstErrorOf = (
    errors: Record<string, string | undefined>,
): string | null =>
    Object.values(errors).find((message) => Boolean(message)) ?? null;

/** The server's `message` for a request that threw, ignoring an empty one. */
export const serverMessageOf = (error: unknown): string | null =>
    extractErrorMessage(error) || null;

/** The HTTP status of a request that threw, or null for a network error. */
export const statusOf = (error: unknown): number | null =>
    (error as { response?: { status?: number } } | null)?.response?.status ??
    null;
