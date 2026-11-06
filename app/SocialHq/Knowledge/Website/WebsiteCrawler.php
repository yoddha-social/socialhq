<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\Models\Workspace;
use App\SocialHq\Knowledge\Sources\FetchedSource;
use App\SocialHq\Knowledge\Sources\SourceFetcher;
use App\SocialHq\Knowledge\Sources\SourceUnavailable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Sleep;
use Illuminate\Support\Uri;
use Throwable;

/**
 * Reads a brand's website politely: robots.txt first (its rules, its
 * Crawl-delay and its sitemaps), then sitemap.xml, then the links on the
 * home page; the same site only (with or without "www."), HTML pages only,
 * up to the configured number of pages, with a pause between requests and
 * a time limit for the whole run. Every request goes through SourceFetcher
 * (no private addresses, on any redirect hop).
 *
 * A page whose HTML hasn't changed since the last run (304 to its ETag or
 * Last-Modified, or the same hash) is not cleaned again: its stored text is
 * used. Only the pages of this run are kept (CrawledPage).
 */
final class WebsiteCrawler
{
    /** Extensions that are never a web page. */
    private const FILES = '/\.(pdf|jpe?g|png|gif|webp|svg|ico|css|js|json|xml|txt|zip|rar|gz|mp4|mov|webm|mp3|wav|docx?|xlsx?|pptx?|csv|woff2?|ttf|eot)$/i';

    private bool $requested = false;

    private int $delayMilliseconds = 0;

    public function __construct(private readonly SourceFetcher $fetcher) {}

    /**
     * @throws CrawlStopped
     */
    public function crawl(Workspace $workspace, string $startUrl): CrawlResult
    {
        $start = self::normalize($startUrl) ?? throw CrawlFailure::Unreachable->exception();
        $uri = Uri::of($start);
        $site = (string) preg_replace('/^www\./', '', strtolower((string) $uri->host()));
        $sameSite = fn (string $host): bool => $host === $site || $host === "www.{$site}";
        $origin = strtolower((string) $uri->scheme()).'://'.strtolower((string) $uri->host());
        $deadline = CarbonImmutable::now()->addSeconds(max(10, (int) self::config('max_seconds', 120)));
        $maxPages = max(1, (int) self::config('max_pages', 25));

        $robots = $this->robots($origin, $sameSite, $deadline);
        $this->delayMilliseconds = (int) max((int) self::config('delay_ms', 1000), min(10000, ($robots->crawlDelay ?? 0) * 1000));

        $queue = [$start, ...$this->sitemapPages($origin, $robots, $sameSite, $deadline)];
        $seen = [];
        $pages = [];
        $result = new CrawlResult;

        while ($queue !== [] && count($pages) < $maxPages && CarbonImmutable::now()->lessThan($deadline)) {
            $url = self::normalize((string) array_shift($queue));

            if ($url === null || isset($seen[$url]) || ! $sameSite(strtolower((string) Uri::of($url)->host())) || preg_match(self::FILES, Uri::of($url)->path()) === 1) {
                continue;
            }

            $seen[$url] = true;
            $isStart = $pages === [] && $url === $start;

            if (! $robots->allows(self::pathOf($url))) {
                $result->skipped++;

                if ($isStart) {
                    throw CrawlFailure::Robots->exception();
                }

                continue;
            }

            $page = $this->page($workspace, $url, $sameSite, $deadline, $isStart, $result);

            if ($page === null) {
                if ($isStart) {
                    throw CrawlFailure::Unreachable->exception();
                }

                $result->skipped++;

                continue;
            }

            if ($isStart) {
                // The home page's links come after the sitemap's pages.
                array_push($queue, ...$page['links']);
                $result->colours = $page['colours'];
                $result->fonts = $page['fonts'];
            }

            $pages[] = ['url' => $page['url'], 'title' => $page['title'], 'text' => $page['text']];
        }

        if ($pages !== []) {
            CrawledPage::query()->where('workspace_id', $workspace->id)->whereNotIn('url', array_column($pages, 'url'))->delete();
        }

        $result->pages = $pages;

        return $result;
    }

    /**
     * A URL as stored and compared: http(s), lower-case host, no fragment,
     * no user or password, no port other than the scheme's own.
     */
    public static function normalize(string $url): ?string
    {
        try {
            $uri = Uri::of(trim($url));
        } catch (Throwable) {
            return null;
        }

        $scheme = strtolower((string) $uri->scheme());
        $host = strtolower((string) $uri->host());

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || filled($uri->user()) || $uri->port() !== null || strlen($url) > 500) {
            return null;
        }

        $path = $uri->path() === '/' ? '/' : '/'.$uri->path();
        $query = $uri->query()->value();

        return "{$scheme}://{$host}{$path}".($query !== '' ? "?{$query}" : '');
    }

    /**
     * @param  Closure(string): bool  $sameSite
     */
    private function robots(string $origin, Closure $sameSite, CarbonImmutable $deadline): RobotsTxt
    {
        try {
            $fetched = $this->get("{$origin}/robots.txt", (int) self::config('robots_kilobytes', 512) * 1024, $sameSite, $deadline);
        } catch (SourceUnavailable $unavailable) {
            if ($unavailable->reason === SourceUnavailable::PRIVATE_NETWORK) {
                throw CrawlFailure::PrivateNetwork->exception();
            }

            return RobotsTxt::allowAll();
        }

        return match (true) {
            // A server error: the site may not want us now (RFC 9309).
            $fetched->status() >= 500 => RobotsTxt::disallowAll(),
            $fetched->status() === 200 && ! $fetched->isHtml() => RobotsTxt::parse($fetched->body()),
            default => RobotsTxt::allowAll(),
        };
    }

    /**
     * The pages listed in the site's sitemaps (robots.txt's, else /sitemap.xml),
     * a sitemap index followed one level, a few files at most.
     *
     * @param  Closure(string): bool  $sameSite
     * @return list<string>
     */
    private function sitemapPages(string $origin, RobotsTxt $robots, Closure $sameSite, CarbonImmutable $deadline): array
    {
        $files = array_values(array_filter($robots->sitemaps, fn (string $url): bool => ($normal = self::normalize($url)) !== null && $sameSite(strtolower((string) Uri::of($normal)->host()))));
        $files = $files === [] ? ["{$origin}/sitemap.xml"] : $files;
        $pages = [];
        $read = 0;

        while ($files !== [] && $read < max(1, (int) self::config('max_sitemaps', 3))) {
            $file = (string) array_shift($files);
            $read++;

            try {
                $fetched = $this->get($file, (int) self::config('sitemap_kilobytes', 5120) * 1024, $sameSite, $deadline);
            } catch (SourceUnavailable) {
                continue;
            }

            if ($fetched->status() !== 200 || $fetched->isHtml()) {
                continue;
            }

            $sitemap = Sitemap::parse($fetched->body());
            array_push($pages, ...$sitemap['pages']);

            foreach ($sitemap['sitemaps'] as $child) {
                $normal = self::normalize($child);

                if ($normal !== null && $sameSite(strtolower((string) Uri::of($normal)->host()))) {
                    $files[] = $normal;
                }
            }
        }

        return array_slice($pages, 0, 500);
    }

    /**
     * One page: its stored text when unchanged, else its HTML cleaned. Null
     * when it isn't an HTML page that answered 200.
     *
     * @param  Closure(string): bool  $sameSite
     * @return array{url: string, title: string, text: string, links: list<string>, colours: list<string>, fonts: list<string>}|null
     */
    private function page(Workspace $workspace, string $url, Closure $sameSite, CarbonImmutable $deadline, bool $isStart, CrawlResult $result): ?array
    {
        $stored = CrawledPage::query()->where('workspace_id', $workspace->id)->where('url', $url)->first();
        // The home page is always read in full: its links and brand hints are needed.
        $headers = ! $isStart && $stored !== null ? array_filter([
            'If-None-Match' => (string) $stored->etag,
            'If-Modified-Since' => (string) $stored->last_modified,
        ]) : [];

        try {
            $fetched = $this->get($url, (int) self::config('page_kilobytes', 2048) * 1024, $sameSite, $deadline, $headers);
        } catch (SourceUnavailable $unavailable) {
            if ($unavailable->reason === SourceUnavailable::PRIVATE_NETWORK && $isStart) {
                throw CrawlFailure::PrivateNetwork->exception();
            }

            return null;
        }

        if ($fetched->status() === 304 && $stored !== null) {
            $result->unchanged++;

            return ['url' => $url, 'title' => (string) $stored->title, 'text' => (string) $stored->text, 'links' => [], 'colours' => [], 'fonts' => []];
        }

        if ($fetched->status() !== 200 || ! $fetched->isHtml()) {
            return null;
        }

        $hash = hash('sha256', $fetched->body());
        $validators = [
            'etag' => mb_substr($fetched->header('ETag'), 0, 200) ?: null,
            'last_modified' => mb_substr($fetched->header('Last-Modified'), 0, 100) ?: null,
        ];

        // The same HTML as last time: its stored text, without cleaning it again.
        if ($stored !== null && $stored->content_hash === $hash && ! $isStart) {
            $result->unchanged++;
            $stored->update($validators);

            return ['url' => $url, 'title' => (string) $stored->title, 'text' => (string) $stored->text, 'links' => [], 'colours' => [], 'fonts' => []];
        }

        $extracted = PageText::extract($fetched->body(), $fetched->url);

        if ($stored !== null && $stored->content_hash === $hash) {
            $result->unchanged++;
        } else {
            $result->read++;
        }

        CrawledPage::query()->updateOrCreate(['workspace_id' => $workspace->id, 'url' => $url], [
            ...$validators,
            'content_hash' => $hash,
            'title' => mb_substr($extracted->title, 0, 300) ?: null,
            'text' => $extracted->text,
        ]);

        return ['url' => $url, 'title' => $extracted->title, 'text' => $extracted->text, 'links' => $extracted->links, 'colours' => $extracted->colours, 'fonts' => $extracted->fonts];
    }

    /**
     * @param  Closure(string): bool  $sameSite
     * @param  array<string, string>  $headers
     *
     * @throws SourceUnavailable
     */
    private function get(string $url, int $maxBytes, Closure $sameSite, CarbonImmutable $deadline, array $headers = []): FetchedSource
    {
        // A pause between requests, never before the first.
        if ($this->requested && $this->delayMilliseconds > 0) {
            Sleep::for($this->delayMilliseconds)->milliseconds();
        }

        $this->requested = true;

        return $this->fetcher->fetch($url, $maxBytes, $sameSite, $headers, (int) self::config('timeout_seconds', 15), $deadline);
    }

    private static function pathOf(string $url): string
    {
        $uri = Uri::of($url);
        $query = $uri->query()->value();

        return ($uri->path() === '/' ? '/' : '/'.$uri->path()).($query !== '' ? "?{$query}" : '');
    }

    private static function config(string $key, mixed $default): mixed
    {
        return config("socialhq.knowledge.website.{$key}", $default);
    }
}
