<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\SocialHq\Knowledge\SafeXml;

/**
 * The page and sitemap locations in a sitemap.xml (a urlset or a sitemap
 * index). Parsed without network access, and a DOCTYPE refuses the file.
 */
final class Sitemap
{
    /**
     * @return array{pages: list<string>, sitemaps: list<string>}
     */
    public static function parse(string $xml): array
    {
        $empty = ['pages' => [], 'sitemaps' => []];

        $document = SafeXml::load($xml);

        if ($document === null) {
            return $empty;
        }

        $root = strtolower((string) $document->documentElement?->localName);
        $locations = [];

        foreach ($document->getElementsByTagNameNS('*', 'loc') as $location) {
            $value = trim($location->textContent);

            if ($value !== '') {
                $locations[] = $value;
            }

            if (count($locations) >= 2000) {
                break;
            }
        }

        return $root === 'sitemapindex' ? ['pages' => [], 'sitemaps' => $locations] : ['pages' => $locations, 'sitemaps' => []];
    }
}
