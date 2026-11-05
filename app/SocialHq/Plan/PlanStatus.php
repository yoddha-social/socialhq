<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

/**
 * Where a monthly plan is in its review: drafted, sent for review, or approved.
 */
enum PlanStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
}
