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
    case Other = 'other';

    public function promptLabel(): string
    {
        return match ($this) {
            self::Product => 'Product or service',
            self::Project => 'Project',
            self::Campaign => 'Past campaign',
            self::Fact => 'Company fact',
            self::Other => 'Note',
        };
    }
}
