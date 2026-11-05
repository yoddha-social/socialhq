<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Enums\User\Locale;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Temperature(0.2)]
class MediaAltTextGenerator implements Agent
{
    use Promptable;
    use UsesAiTask;

    public const int MAX_LENGTH = 250;

    public function __construct(
        public Locale $locale,
    ) {}

    /**
     * Social HQ: where AiRouter sends this agent's calls.
     */
    public function aiTask(): AiTask
    {
        return AiTask::Vision;
    }

    public function instructions(): string
    {
        return view('prompts.post_image.alt_text', [
            'language' => $this->locale->promptLanguage(),
            'max_length' => self::MAX_LENGTH,
        ])->render();
    }
}
