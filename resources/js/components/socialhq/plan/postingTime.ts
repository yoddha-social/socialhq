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
): PostingTime | null => {
    if (!item.suggested_at || !item.suggested_timezone) {
        return null;
    }

    const viewerZone = userTimezone.value;

    return {
        time: date.formatTimeInTimezone(
            item.suggested_at,
            item.suggested_timezone,
        ),
        place: placeOfZone(item.suggested_timezone),
        viewerTime: date.formatTimeInTimezone(item.suggested_at, viewerZone),
        viewerPlace: placeOfZone(viewerZone),
        sameZone: viewerZone === item.suggested_timezone,
    };
};
