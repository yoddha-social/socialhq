<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use App\SocialHq\Ai\Company\CompanyRoute;
use App\SocialHq\Ai\Company\CompanyRouting;
use Laravel\Ai\Events\GeneratingAudio;
use Laravel\Ai\Events\GeneratingEmbeddings;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\GeneratingTranscription;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\Reranking;
use Laravel\Ai\Events\StreamingAgent;

/**
 * The last line of the approved-providers rule. AiRouter only routes to
 * approved providers, but code that calls the SDK without it (an agent
 * without UsesAiTask, `agent()`, `Image::of()->generate()` with no provider)
 * would reach `ai.default`. The SDK fires these events before each request,
 * failovers included, so refusing here stops any call to a provider that may
 * train on prompts, whoever made it.
 */
final class ApprovedProvidersGuard
{
    /**
     * @var list<class-string>
     */
    public const array EVENTS = [
        PromptingAgent::class,
        StreamingAgent::class,
        GeneratingImage::class,
        GeneratingEmbeddings::class,
        GeneratingAudio::class,
        GeneratingTranscription::class,
        Reranking::class,
    ];

    /**
     * @throws AiUnavailableException
     */
    public function handle(object $event): void
    {
        $provider = property_exists($event, 'provider')
            ? $event->provider->name()
            : $event->prompt->provider->name();

        if (! AiRouter::isApproved($provider)) {
            throw new AiUnavailableException("The AI provider [{$provider}] is not approved (SOCIALHQ_AI_APPROVED_PROVIDERS).");
        }

        // The platform's LiteLLM entries carry the platform's key: a company
        // reaches LiteLLM only through its own entry (CompanyRouting).
        if (in_array($provider, [AiRouter::LITELLM, AiRouter::LITELLM_IMAGES], true)
            && AiRouter::usesGateway()
            && CompanyRouting::current()->kind !== CompanyRoute::PLATFORM) {
            throw new AiUnavailableException("The platform's AI [{$provider}] is not available to this company.");
        }
    }
}
