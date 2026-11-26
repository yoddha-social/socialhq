<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The standard browser safety headers on every response the app makes
 * (audit 2026-11-24, SEC-09): the browser never guesses a file's type, a
 * link to another site carries only our origin as the referrer, and only
 * our own pages may show the app in a frame. SAMEORIGIN, not DENY: the
 * composer previews a PDF in an iframe from our own media route. Nothing
 * outside frames the app: channel and MCP sign-ins are full-page
 * redirects. A response that sets one of them keeps its own (the connect
 * link's page sends no referrer at all).
 *
 * nginx adds the same three to files it serves from disk (docker/nginx.conf).
 * The script allow-list (Content-Security-Policy) is separate work, and
 * HTTPS-only (HSTS) is switched on at Cloudflare.
 */
class SecurityHeaders
{
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'SAMEORIGIN',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
