<?php

declare(strict_types=1);

namespace App\SocialHq\Support;

use Closure;

/**
 * Remembers a value for the rest of the current web request (the shared
 * Inertia props, middleware and controllers ask the same questions). Never
 * in a queue worker or command, where one request object lives for hours.
 */
final class PerRequest
{
    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public static function remember(string $key, Closure $resolve): mixed
    {
        $request = request();

        if ($request->route() === null) {
            return $resolve();
        }

        $key = "socialhq.per-request:{$key}";

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $resolve());
        }

        return $request->attributes->get($key);
    }

    public static function forget(string $key): void
    {
        request()->attributes->remove("socialhq.per-request:{$key}");
    }
}
