import type { PlanItem } from '@/components/socialhq/plan/types';
import date from '@/date';
import { userTimezone } from '@/preferences';

/** A zone as people read it: "Australia/Sydney" → "Sydney". */
export const placeOfZone = (zone: string): string =>
    (zone.split('/').pop() ?? zone).replaceAll('_', ' ');

/**
 * An item's posting time in its own zone (the main audience zone it was
 * written in) and in the viewer's zone. `sameZone` when both are the same,
 * so only one is shown. Null without a time.
 */
export interface PostingTime {
    time: string;
    place: string;
    viewerTime: string;
    viewerPlace: string;
    sameZone: boolean;
}

export const postingTimeOf = (
    item: Pick<PlanItem, 'suggested_at' | 'suggested_timezone'>,
): PostingTime | null => postingTimeIn(item, userTimezone.value);

/**
 * The same, with the second time in a given zone instead of the viewer's
 * (the channel's, which the composer shows).
 */
export const postingTimeIn = (
    item: Pick<PlanItem, 'suggested_at' | 'suggested_timezone'>,
    zone: string,
): PostingTime | null => {
    if (!item.suggested_at || !item.suggested_timezone) {
        return null;
    }

    return {
        time: date.formatTimeInTimezone(
            item.suggested_at,
            item.suggested_timezone,
        ),
        place: placeOfZone(item.suggested_timezone),
        viewerTime: date.formatTimeInTimezone(item.suggested_at, zone),
        viewerPlace: placeOfZone(zone),
        sameZone: zone === item.suggested_timezone,
    };
};
