<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

/**
 * What a WebsiteCrawler run read.
 */
final class CrawlResult
{
    /** @var list<array{url: string, title: string, text: string}> */
    public array $pages = [];

    /** @var list<string> Hex colours of the home page's markup */
    public array $colours = [];

    /** @var list<string> Font families of the home page's markup */
    public array $fonts = [];

    /** Pages cleaned this run. */
    public int $read = 0;

    /** Pages unchanged since the last run (their stored text used). */
    public int $unchanged = 0;

    /** Pages left out: refused by robots.txt, not HTML, or not answering. */
    public int $skipped = 0;
}
