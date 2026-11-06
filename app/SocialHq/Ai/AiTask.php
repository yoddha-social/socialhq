<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;

/**
 * What an AI call is for. Callers name a task, never a provider or model:
 * AiRouter turns the task into an ordered list of provider and model pairs.
 * With the LiteLLM gateway each task is one LiteLLM model group, which holds
 * the free-first chain (socialhq-deploy/litellm/config.yaml).
 */
enum AiTask: string
{
    /** Captions: write, rewrite, shorten, repurpose. */
    case Write = 'write';

    /** Answers that must match a JSON schema, such as content ideas. */
    case Structured = 'structured';

    /** Looks at images: alt text and the safety check of AI graphics. */
    case Vision = 'vision';

    /** Generates or edits a graphic. */
    case Image = 'image';

    /** Embeddings for the knowledge base. One fixed model: changing it means re-embedding. */
    case Embed = 'embed';

    /**
     * The SDK contract a provider needs for this task.
     *
     * @return class-string
     */
    public function contract(): string
    {
        return match ($this) {
            self::Image => ImageProvider::class,
            self::Embed => EmbeddingProvider::class,
            default => TextProvider::class,
        };
    }

    /**
     * The LiteLLM model group that serves this task.
     */
    public function litellmGroup(): string
    {
        return 'shq-'.$this->value;
    }

    /**
     * The provider entry that reaches LiteLLM for this task. Text and
     * embeddings use the openai-compatible driver (chat/completions); images
     * use the openai driver, the one that speaks the images endpoints.
     */
    public function litellmProvider(): string
    {
        return $this === self::Image ? AiRouter::LITELLM_IMAGES : AiRouter::LITELLM;
    }
}
