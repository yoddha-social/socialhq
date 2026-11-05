<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

/**
 * One entry in an item's review thread.
 */
enum ReviewKind: string
{
    case Comment = 'comment';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
}
