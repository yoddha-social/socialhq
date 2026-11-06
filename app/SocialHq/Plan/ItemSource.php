<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

/**
 * Who first wrote something: a person or the AI.
 */
enum ItemSource: string
{
    case Manual = 'manual';
    case Ai = 'ai';
}
