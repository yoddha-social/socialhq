<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use Illuminate\Http\Client\Response;

/**
 * What SourceFetcher got: the final URL and the response there.
 */
final readonly class FetchedSource
{
    public function __construct(public string $url, public Response $response) {}

    public function status(): int
    {
        return $this->response->status();
    }

    public function body(): string
    {
        return $this->response->body();
    }

    public function header(string $name): string
    {
        return trim($this->response->header($name));
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('Content-Type'))[0]));
    }

    public function isHtml(): bool
    {
        return in_array($this->contentType(), ['text/html', 'application/xhtml+xml'], true);
    }
}
