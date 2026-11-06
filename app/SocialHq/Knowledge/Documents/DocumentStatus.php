<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

/**
 * Where a knowledge document is: its text is being read, it is ready for
 * search, or it couldn't be read (with a DocumentFailure).
 */
enum DocumentStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
