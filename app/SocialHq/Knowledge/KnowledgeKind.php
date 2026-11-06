<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

/**
 * What a knowledge entry is about. Only groups entries on the page and tells
 * the AI what kind of fact it is reading.
 */
enum KnowledgeKind: string
{
    case Product = 'product';
    case Project = 'project';
    case Campaign = 'campaign';
    case Fact = 'fact';
    case ExamplePost = 'example_post';
    case Other = 'other';

    public function promptLabel(): string
    {
        return match ($this) {
            self::Product => 'Product or service',
            self::Project => 'Project',
            self::Campaign => 'Past campaign',
            self::Fact => 'Company fact',
            self::ExamplePost => 'Example post',
            self::Other => 'Note',
        };
    }

    /**
     * Example posts show the AI how the brand sounds. They go in their own
     * prompt section, read for tone, not for facts.
     */
    public function isExample(): bool
    {
        return $this === self::ExamplePost;
    }
}
