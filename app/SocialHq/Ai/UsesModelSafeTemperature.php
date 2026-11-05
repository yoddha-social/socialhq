<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

/**
 * For laravel/ai agents: sends the agent's preferred temperature only when
 * the configured model accepts one. Replaces the #[Temperature] attribute,
 * which laravel/ai would apply even when the method returns null.
 */
trait UsesModelSafeTemperature
{
    abstract protected function preferredTemperature(): float;

    public function temperature(): ?float
    {
        $accepts = method_exists($this, 'aiTask')
            ? ModelCapabilities::acceptsTemperatureFor($this->aiTask())
            : ModelCapabilities::acceptsTemperature();

        return $accepts ? $this->preferredTemperature() : null;
    }
}
