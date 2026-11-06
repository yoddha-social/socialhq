<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

/**
 * What the configured text model accepts.
 *
 * Newer models (Anthropic's Claude 5 family, for example) reject a
 * `temperature` parameter outright ("temperature is deprecated for this
 * model"), which makes every TryPost AI feature fail. Models listed in
 * config('socialhq.ai.models_without_temperature') are sent no temperature.
 */
final class ModelCapabilities
{
    public static function acceptsTemperature(?string $model = null): bool
    {
        $model ??= self::defaultTextModel();

        if ($model === null) {
            return true;
        }

        foreach (config('socialhq.ai.models_without_temperature', []) as $pattern) {
            if (preg_match($pattern, $model) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every model the task's route may reach accepts a temperature.
     * A target without a model uses its provider's default text model.
     */
    public static function acceptsTemperatureFor(AiTask $task): bool
    {
        try {
            $route = AiRouter::route($task);
        } catch (AiUnavailableException) {
            return true;
        }

        foreach ($route as $provider => $model) {
            $model ??= config("ai.providers.{$provider}.models.text.default");

            if (is_string($model) && $model !== '' && ! self::acceptsTemperature($model)) {
                return false;
            }
        }

        return true;
    }

    public static function defaultTextModel(): ?string
    {
        $provider = config('ai.default');

        $model = config("ai.providers.{$provider}.models.text.default");

        return is_string($model) && $model !== '' ? $model : null;
    }
}
