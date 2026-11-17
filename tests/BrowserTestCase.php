<?php

declare(strict_types=1);

namespace Tests;

abstract class BrowserTestCase extends TestCase
{
    /**
     * Browser tests drive a real browser and load the built Vite assets, so the
     * manifest must not be faked away.
     */
    protected bool $fakesVite = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Social HQ: the AI buttons show only for a task with a usable provider
        // (the shared aiTasks prop, AiRouter::isAvailable). Production has keys;
        // here the agents are faked, so placeholder keys stand in and nothing is
        // ever sent.
        config([
            'ai.providers.openai.key' => 'browser-test-key',
            'ai.providers.anthropic.key' => 'browser-test-key',
            'ai.providers.gemini.key' => 'browser-test-key',
        ]);
    }
}
