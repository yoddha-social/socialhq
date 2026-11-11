<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

enum CredentialStatus: string
{
    /** Saved but not tested since (a key is always tested before it is saved). */
    case Untested = 'untested';

    /** The last test answered. */
    case Ok = 'ok';

    /** The last test failed; its deployments stay, LiteLLM fails over to the next provider. */
    case Failed = 'failed';
}
