<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

/**
 * Whether an AI draft of the plan is running, and how the last one ended.
 */
enum GenerationStatus: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Failed = 'failed';
}
