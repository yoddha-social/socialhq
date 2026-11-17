const firstCharacter = (word: string): string => Array.from(word)[0] ?? '';

// Words of a name, each from its first letter on (drops quotes, emoji, digits).
const wordsOf = (text: string): string[] =>
    text
        .split(/\s+/u)
        .map((word) => word.replace(/^[^\p{L}]+/u, ''))
        .filter((word) => word !== '');

/**
 * Initials from the first and last words of the name, leaving out anything in
 * brackets: "राम थापा (Ram Thapa)" gives "रथ", not "रT".
 */
export const getInitials = (fullName?: string): string => {
    if (!fullName) return '';

    const outsideBrackets = wordsOf(
        fullName.replace(/[([{][^)\]}]*[)\]}]/gu, ' '),
    );
    const names =
        outsideBrackets.length > 0 ? outsideBrackets : wordsOf(fullName);

    if (names.length === 0) return '';
    if (names.length === 1) return firstCharacter(names[0]).toUpperCase();

    return `${firstCharacter(names[0])}${firstCharacter(names[names.length - 1])}`.toUpperCase();
};

export const useInitials = () => ({ getInitials });
