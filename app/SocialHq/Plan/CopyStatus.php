<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

/**
 * The review state of an item's copy (caption, hashtags, script).
 */
enum CopyStatus: string
{
    case None = 'none';
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
}
