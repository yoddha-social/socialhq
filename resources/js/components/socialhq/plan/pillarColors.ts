/**
 * One colour per content pillar, picked by the pillar's place in the brand's
 * list, so a pillar keeps its colour on every page until the list is reordered.
 * Eight colours for the eight pillars a brand may have; readable in both themes.
 */
const PALETTE = [
    { dot: 'bg-sky-500', soft: 'bg-sky-500/10 text-sky-700 dark:text-sky-300' },
    {
        dot: 'bg-violet-500',
        soft: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    },
    {
        dot: 'bg-emerald-500',
        soft: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    },
    {
        dot: 'bg-amber-500',
        soft: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    },
    {
        dot: 'bg-rose-500',
        soft: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
    },
    {
        dot: 'bg-teal-500',
        soft: 'bg-teal-500/10 text-teal-700 dark:text-teal-300',
    },
    {
        dot: 'bg-indigo-500',
        soft: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300',
    },
    {
        dot: 'bg-orange-500',
        soft: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
    },
] as const;

/** A pillar that is no longer in the brand's list (renamed or removed). */
const UNLISTED = {
    dot: 'bg-muted-foreground/50',
    soft: 'bg-muted text-muted-foreground',
};

export interface PillarColor {
    dot: string;
    soft: string;
}

/** The colour of the pillar at this place in the brand's list. */
export const pillarColorAt = (index: number): PillarColor =>
    PALETTE[index % PALETTE.length];

export const pillarColor = (
    pillar: string | null,
    pillars: string[],
): PillarColor | null => {
    if (!pillar) {
        return null;
    }

    const index = pillars.indexOf(pillar);

    return index === -1 ? UNLISTED : pillarColorAt(index);
};
