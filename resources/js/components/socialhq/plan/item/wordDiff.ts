export interface DiffPart {
    text: string;
    change: 'same' | 'added' | 'removed';
}

/** Words and the whitespace after them, so joining the parts gives the text back. */
const tokens = (text: string): string[] => text.match(/\S+\s*|\s+/g) ?? [];

const MAX_CELLS = 4_000_000;

/**
 * A word-level diff of two texts (longest common subsequence). Texts too long
 * for the table come back as one removal and one addition.
 */
export const wordDiff = (before: string, after: string): DiffPart[] => {
    const a = tokens(before);
    const b = tokens(after);

    if (a.length * b.length > MAX_CELLS) {
        return [
            { text: before, change: 'removed' },
            { text: after, change: 'added' },
        ].filter((part) => part.text !== '') as DiffPart[];
    }

    const width = b.length + 1;
    const table = new Uint32Array((a.length + 1) * width);

    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            table[i * width + j] =
                a[i].trim() === b[j].trim()
                    ? table[(i + 1) * width + j + 1] + 1
                    : Math.max(
                          table[(i + 1) * width + j],
                          table[i * width + j + 1],
                      );
        }
    }

    const parts: DiffPart[] = [];
    const push = (text: string, change: DiffPart['change']): void => {
        const last = parts[parts.length - 1];

        if (last && last.change === change) {
            last.text += text;
        } else {
            parts.push({ text, change });
        }
    };

    let i = 0;
    let j = 0;

    while (i < a.length && j < b.length) {
        if (a[i].trim() === b[j].trim()) {
            push(b[j], 'same');
            i++;
            j++;
        } else if (table[(i + 1) * width + j] >= table[i * width + j + 1]) {
            push(a[i], 'removed');
            i++;
        } else {
            push(b[j], 'added');
            j++;
        }
    }

    a.slice(i).forEach((token) => push(token, 'removed'));
    b.slice(j).forEach((token) => push(token, 'added'));

    return parts;
};
