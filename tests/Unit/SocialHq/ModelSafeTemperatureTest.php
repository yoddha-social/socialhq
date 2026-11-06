<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\MediaAltTextGenerator;
use App\Ai\Agents\PostContentShortener;
use App\Ai\Agents\PostWritingAssistant;
use App\SocialHq\Ai\ModelCapabilities;
use Laravel\Ai\Gateway\TextGenerationOptions;

function useTextModel(string $model): void
{
    config()->set('ai.default', 'anthropic');
    config()->set('ai.providers.anthropic.models.text.default', $model);
}

test('Claude 5 models are recognised as rejecting temperature', function (string $model, bool $accepts) {
    expect(ModelCapabilities::acceptsTemperature($model))->toBe($accepts);
})->with([
    ['claude-sonnet-5-5', false],
    ['claude-haiku-5-5', false],
    ['claude-opus-5-5', false],
    ['claude-sonnet-5-5-20261001', false],
    ['claude-sonnet-4-5', true],
    ['claude-haiku-4-5', true],
    ['claude-sonnet-4-5-20250929', true],
    ['claude-3-5-sonnet-20241022', true],
    ['gpt-5.4', true],
    ['gemini-2.5-flash', true],
]);

test('the default text model of the configured provider is checked', function () {
    useTextModel('claude-sonnet-5-5');
    expect(ModelCapabilities::acceptsTemperature())->toBeFalse();

    useTextModel('claude-sonnet-4-5');
    expect(ModelCapabilities::acceptsTemperature())->toBeTrue();
});

test('agents keep their temperature for models that accept it and send none otherwise', function (string $agent, float $temperature) {
    $instance = (new ReflectionClass($agent))->newInstanceWithoutConstructor();

    useTextModel('claude-sonnet-4-5');
    expect(TextGenerationOptions::forAgent($instance)->temperature)->toBe($temperature);

    useTextModel('claude-sonnet-5-5');
    expect(TextGenerationOptions::forAgent($instance)->temperature)->toBeNull();
})->with([
    [IdeaGenerator::class, 0.8],
    [MediaAltTextGenerator::class, 0.2],
    [PostContentShortener::class, 0.2],
    [PostWritingAssistant::class, 0.7],
]);
