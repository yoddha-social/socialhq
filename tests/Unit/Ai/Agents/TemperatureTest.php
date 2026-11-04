<?php

declare(strict_types=1);

use App\Ai\Concerns\OmitsUnsupportedTemperature;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Gateway\TextGenerationOptions;

$agents = fn (): array => collect(glob(dirname(__DIR__, 4).'/app/Ai/Agents/*.php'))
    ->map(fn (string $path): string => 'App\\Ai\\Agents\\'.basename($path, '.php'))
    ->all();

function useAnthropicTextModel(string $model): void
{
    config()->set('ai.default', 'anthropic');
    config()->set('ai.providers.anthropic.models.text.default', $model);
}

test('agents set their temperature through the trait, never the attribute', function (string $agent) {
    expect((new ReflectionClass($agent))->getAttributes(Temperature::class))->toBeEmpty();
})->with($agents);

test('the Claude 5 family is recognised as rejecting temperature', function (string $model, bool $accepts) {
    $agent = new class
    {
        use OmitsUnsupportedTemperature;

        protected function preferredTemperature(): float
        {
            return 0.5;
        }
    };

    expect($agent::modelAcceptsTemperature($model))->toBe($accepts);
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

test('agents send their temperature only to models that accept it', function (string $agent) {
    $instance = (new ReflectionClass($agent))->newInstanceWithoutConstructor();

    useAnthropicTextModel('claude-sonnet-4-5');
    $temperature = TextGenerationOptions::forAgent($instance)->temperature;

    useAnthropicTextModel('claude-sonnet-5-5');
    expect(TextGenerationOptions::forAgent($instance)->temperature)->toBeNull();

    if ($temperature !== null) {
        expect(in_array(OmitsUnsupportedTemperature::class, class_uses_recursive($agent), true))->toBeTrue();
    }
})->with($agents);
