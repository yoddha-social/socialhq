<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use App\Services\Http\SafeHttpFetcher;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Uri;
use RuntimeException;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * Fetches a Google export or a website page for brand knowledge, through
 * SafeHttpFetcher: http(s) only, every host resolved and refused when it
 * is a private, loopback or link-local address (and the request pinned to
 * the address checked), a size cap on the bytes received and a time cap.
 *
 * Redirects are followed here, one hop at a time, and each target must be
 * a host the caller allows (the same site, or Google's own hosts) on its
 * scheme's default port; any other target stops the fetch with
 * SourceUnavailable::REDIRECT_ELSEWHERE. A body sent compressed although
 * we asked for none is SourceUnavailable::ENCODED (it is never decoded).
 */
final class SourceFetcher
{
    private const MAX_HOPS = 3;

    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    /**
     * @param  Closure(string): bool  $allowsHost  Whether a redirect may go to this host (lower case)
     * @param  array<string, string>  $headers
     *
     * @throws SourceUnavailable
     */
    public function fetch(string $url, int $maxBytes, Closure $allowsHost, array $headers = [], int $timeoutSeconds = 15, ?CarbonInterface $deadline = null): FetchedSource
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            try {
                $this->fetcher->guardAgainstSsrf($current);
            } catch (RuntimeException) {
                throw new SourceUnavailable(SourceUnavailable::PRIVATE_NETWORK, $current);
            }

            $exceeded = false;

            try {
                $response = $this->fetcher
                    ->limitTransfer(
                        $this->fetcher->guardedRequest($current, followRedirects: false, deadline: $deadline),
                        $maxBytes,
                        $deadline,
                        $timeoutSeconds,
                        function () use (&$exceeded): void {
                            $exceeded = true;
                        },
                    )
                    ->withHeaders($headers)
                    ->get($current);
            } catch (ConnectionException|RuntimeException) {
                throw new SourceUnavailable($exceeded ? SourceUnavailable::TOO_BIG : SourceUnavailable::UNREACHABLE, $current);
            }

            if (strlen($response->body()) > $maxBytes) {
                throw new SourceUnavailable(SourceUnavailable::TOO_BIG, $current);
            }

            $location = trim($response->header('Location'));

            if (! $response->redirect() || $location === '') {
                // Asked for identity: a body compressed anyway would be read as binary text.
                $encoding = strtolower(trim($response->header('Content-Encoding')));

                if ($response->body() !== '' && ! in_array($encoding, ['', 'identity'], true)) {
                    throw new SourceUnavailable(SourceUnavailable::ENCODED, $current);
                }

                return new FetchedSource($current, $response);
            }

            $next = (string) UriResolver::resolve($location, $current);
            $uri = Uri::of($next);
            $scheme = strtolower((string) $uri->scheme());

            // The same host on another port is another service: only the scheme's own port.
            if (! in_array($scheme, ['http', 'https'], true) || ! in_array($uri->port(), [null, self::DEFAULT_PORTS[$scheme]], true) || ! $allowsHost(strtolower((string) $uri->host()))) {
                throw new SourceUnavailable(SourceUnavailable::REDIRECT_ELSEWHERE, $next);
            }

            $current = $next;
        }

        throw new SourceUnavailable(SourceUnavailable::TOO_MANY_REDIRECTS, $current);
    }
}
