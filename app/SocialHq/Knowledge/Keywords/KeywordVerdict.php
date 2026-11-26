<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

/**
 * The keyword check's verdict on one SEO keyword. Every verdict other than
 * Good is a warning: the keyword still goes to the AI (owner decision
 * 2026-11-23). In the order the check tries them; the first match wins.
 */
enum KeywordVerdict: string
{
    /** A word or phrase from "Words and phrases to avoid" is in it. */
    case Conflict = 'conflict';

    /** No single place in the brand knowledge holds all of its words. */
    case Weak = 'weak';

    /** Too broad, a claim, a place outside the audience, or a twin of another keyword. */
    case Check = 'check';

    case Good = 'good';
}
