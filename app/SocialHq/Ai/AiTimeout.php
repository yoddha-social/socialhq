<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

/**
 * How long an agent waits for its answer, in seconds. laravel/ai's default
 * (60 s) is shorter than one LiteLLM deployment may take (75–90 s for
 * structured answers), so LiteLLM never got to fall back to the next one.
 * Agents get these through UsesAiTask::timeout().
 */
final class AiTimeout
{
    /**
     * A call someone waits for in the browser. Cloudflare ends a request at
     * about 100 s, so this leaves room for the rest of the request. LiteLLM's
     * per-deployment timeouts must be short enough for a fallback to fit.
     */
    public const int WEB = 90;

    /**
     * A call in a queued job (the planner, the website reader). The job's own
     * $timeout must be longer than this plus the rest of its work.
     */
    public const int QUEUED = 300;
}
