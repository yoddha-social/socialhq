<?php

declare(strict_types=1);

use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\UsesAiTask;

// Social HQ: agents name a task and AiRouter picks the providers (the LiteLLM
// model group or the direct route). None picks a model of its own.
test('agents route through an AI task and do not pick a model', function (string $agent) {
    expect(class_uses_recursive($agent))->toHaveKey(UsesAiTask::class)
        ->and((new ReflectionMethod($agent, 'aiTask'))->getReturnType()?->getName())->toBe(AiTask::class)
        ->and(method_exists($agent, 'model'))->toBeFalse();
})->with(fn (): array => collect(glob(dirname(__DIR__, 4).'/app/Ai/Agents/*.php'))
    ->map(fn (string $path): string => 'App\\Ai\\Agents\\'.basename($path, '.php'))
    ->all());
