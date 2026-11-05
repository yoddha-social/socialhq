<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

/**
 * A plan item's place in the plan: an AI suggestion not yet accepted, part of the plan, or dropped.
 */
enum ItemStatus: string
{
    case Suggested = 'suggested';
    case Planned = 'planned';
    case Dropped = 'dropped';
}
