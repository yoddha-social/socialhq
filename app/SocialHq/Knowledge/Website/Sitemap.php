<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use DOMDocument;

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

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return $empty;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                return $empty;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
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
