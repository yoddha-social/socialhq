<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

/**
 * The shapes offered for an AI graphic. Gemini takes any aspect ratio;
 * OpenAI and xAI only 1:1, 2:3 and 3:2, so the closest is sent there.
 */
enum GraphicFormat: string
{
    case Square = 'square';
    case Portrait = 'portrait';
    case Story = 'story';
    case Landscape = 'landscape';

    /**
     * Gemini takes social media ratios; the OpenAI-style drivers (LiteLLM's
     * images endpoint too) take only square, 2:3 and 3:2.
     */
    public function aspectRatio(?string $driver): string
    {
        $gemini = $driver === 'gemini';

        return match ($this) {
            self::Square => '1:1',
            self::Portrait => $gemini ? '4:5' : '2:3',
            self::Story => $gemini ? '9:16' : '2:3',
            self::Landscape => $gemini ? '16:9' : '3:2',
        };
    }
}
