import type { PlanItem, PlanLookups } from '@/components/socialhq/plan/types';
import dayjs from '@/dayjs';
import { weekStartIndex } from '@/preferences';

export type PlanListSortKey =
    | 'date'
    | 'channel'
    | 'pillar'
    | 'owner'
    | 'status';

export interface PlanListSort {
    key: PlanListSortKey;
    direction: 'asc' | 'desc';
}

export interface PlanWeek {
    /** The first day of the week (the user's week start), "YYYY-MM-DD". */
    start: string;
    items: PlanItem[];
}

const STATUS_ORDER: Record<PlanItem['status'], number> = {
    suggested: 0,
    planned: 1,
    dropped: 2,
};

/** The first day of the item's week, following the user's start of week. */
export const weekStartOf = (day: string): string => {
    const value = dayjs(day);
    const offset = (value.day() - weekStartIndex() + 7) % 7;

    return value.subtract(offset, 'day').format('YYYY-MM-DD');
};

/** The day, then the time (items without one last), then the plan's order. */
const byDate = (a: PlanItem, b: PlanItem): number =>
    a.scheduled_on.localeCompare(b.scheduled_on) ||
    (a.suggested_at ?? '~').localeCompare(b.suggested_at ?? '~') ||
    a.position - b.position ||
    a.id - b.id;

const sortValue = (
    item: PlanItem,
    key: PlanListSortKey,
    lookups: PlanLookups,
): string | number => {
    switch (key) {
        case 'channel':
            return (
                (item.social_account_id
                    ? lookups.channels[item.social_account_id]?.name
                    : null) ??
                item.platform ??
                ''
            ).toLocaleLowerCase();
        case 'pillar': {
            const index = item.pillar
                ? lookups.pillars.indexOf(item.pillar)
                : -1;

            return index === -1 ? (item.pillar ? 900 : 999) : index;
        }
        case 'owner': {
            const name = item.owner_id
                ? lookups.owners[item.owner_id]?.name
                : null;

            // Items nobody owns yet come last.
            return name ? `0${name.toLocaleLowerCase()}` : '1';
        }
        case 'status':
            return STATUS_ORDER[item.status];
        default:
            return 0;
    }
};

/**
 * The items by week (oldest first), each week sorted by the chosen column.
 * Sorting never moves an item to another week, so the week headers stay put.
 */
export const groupByWeek = (
    items: PlanItem[],
    sort: PlanListSort,
    lookups: PlanLookups,
): PlanWeek[] => {
    const weeks = new Map<string, PlanItem[]>();

    for (const item of items) {
        const start = weekStartOf(item.scheduled_on);
        weeks.set(start, [...(weeks.get(start) ?? []), item]);
    }

    const sign = sort.direction === 'asc' ? 1 : -1;
    const compare = (a: PlanItem, b: PlanItem): number => {
        if (sort.key === 'date') {
            return sign * byDate(a, b);
        }

        const left = sortValue(a, sort.key, lookups);
        const right = sortValue(b, sort.key, lookups);
        const order =
            typeof left === 'number' && typeof right === 'number'
                ? left - right
                : String(left).localeCompare(String(right));

        return sign * order || byDate(a, b);
    };

    return [...weeks.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([start, weekItems]) => ({
            start,
            items: [...weekItems].sort(compare),
        }));
};
