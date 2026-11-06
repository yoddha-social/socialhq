<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use RuntimeException;

/**
 * A page or file couldn't be fetched. The reason is a fixed key; the
 * location is where a refused redirect pointed.
 */
final class SourceUnavailable extends RuntimeException
{
    public const PRIVATE_NETWORK = 'private_network';

    public const REDIRECT_ELSEWHERE = 'redirect_elsewhere';

    public const TOO_MANY_REDIRECTS = 'too_many_redirects';

    public const TOO_BIG = 'too_big';

    public const UNREACHABLE = 'unreachable';

    public function __construct(public readonly string $reason, public readonly ?string $location = null)
    {
        parent::__construct("Source unavailable: {$reason}");
    }
}
