<?php

declare(strict_types=1);

namespace App\SocialHq\Errors;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders 403, 404, 500 and 503 for the browser as an Inertia page in the
 * app's style (`socialhq/Error`) instead of Laravel's bare error views.
 *
 * Only the status code reaches the page: never the exception's message. JSON
 * and API answers (api/*, MCP, requests that expect JSON) are left alone, and
 * so is a 500 while debug mode is on, so local stack traces still show. If the
 * page itself cannot be built (the database is down, no session), the plain
 * error response goes out instead.
 */
final class ErrorPages
{
    /** @var list<int> */
    public const STATUSES = [
        Response::HTTP_FORBIDDEN,
        Response::HTTP_NOT_FOUND,
        Response::HTTP_INTERNAL_SERVER_ERROR,
        Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    public static function register(): void
    {
        Inertia::handleExceptionsUsing(fn (ExceptionResponse $response): ?Response => self::render($response));
    }

    public static function render(ExceptionResponse $response): ?Response
    {
        $status = $response->statusCode();
        $request = $response->request;

        if (! in_array($status, self::STATUSES, true)
            || $response->response instanceof JsonResponse
            || self::wantsData($request)
            || ($status === Response::HTTP_INTERNAL_SERVER_ERROR && config('app.debug'))) {
            return null;
        }

        try {
            $page = $response->render('socialhq/Error', ['status' => $status]);

            // Shared data (the user's theme, language, workspace) needs the
            // session, which an unmatched URL never started.
            if ($request->hasSession()) {
                $page->withSharedData();
            }

            return $page->toResponse($request);
        } catch (Throwable) {
            return null;
        }
    }

    private static function wantsData(Request $request): bool
    {
        return $request->is('api/*', 'mcp/*', 'oauth/*', '.well-known/*')
            || $request->expectsJson();
    }
}
