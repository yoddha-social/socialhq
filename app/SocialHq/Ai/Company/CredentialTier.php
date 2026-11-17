<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

/**
 * The provider plan a company's key is on. A free key's calls cost nothing
 * against the company's budget (its deployments carry zero prices); a paid
 * key's calls are priced by LiteLLM and count toward it.
 */
enum CredentialTier: string
{
    public const DEFAULT = self::Paid;

    case Free = 'free';

    case Paid = 'paid';
}
