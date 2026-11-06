<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

/**
 * Whether a knowledge entry reaches the AI prompt, and if not, why.
 */
enum PromptFit: string
{
    /** In the prompt. */
    case Included = 'included';

    /** Fits on its own, but the entries before it (pinned, then newer) leave no room. */
    case NoRoom = 'no_room';

    /** Bigger than all the room the profile leaves, so it never fits until shortened. */
    case TooLong = 'too_long';

    /** Turned off ("Use in AI writing"). */
    case Off = 'off';
}
