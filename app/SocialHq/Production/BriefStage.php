<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

/**
 * The production board's columns, in order.
 */
enum BriefStage: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
}
