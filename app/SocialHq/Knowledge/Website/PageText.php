<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use App\SocialHq\Knowledge\Documents\DocumentText;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\DomCrawler\UriResolver;
use Throwable;

/**
 * The main text of a web page: what's in <main> (or the largest <article>,
 * or the body), without menus, headers, footers, sidebars, forms, scripts,
 * styles, hidden parts and cookie or newsletter banners. Also the page's
 * links (to find more pages) and the brand hints in its markup: colours
 * (theme-color, inline CSS) and web fonts (Google Fonts links, font-family).
 */
final readonly class PageText
{
    /** Elements that are never content. */
    private const DROPPED = [
        'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed', 'form', 'button',
        'select', 'input', 'textarea', 'nav', 'header', 'footer', 'aside', 'dialog', 'video', 'audio', 'picture', 'map',
    ];

    /** id, class, role or aria-label words of boilerplate: banners, menus, popups. */
    private const BOILERPLATE = '/(^|[\s_-])(cookie|cookies|consent|gdpr|newsletter|popup|modal|subscribe|navbar|breadcrumbs?|sidebar|footer|skip-link|offcanvas)([\s_-]|$)/i';

    private const BLOCKS = [
        'p', 'div', 'section', 'article', 'main', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'tr', 'blockquote',
        'figure', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr', 'address', 'pre', 'details', 'summary',
    ];

    /**
     * @param  list<string>  $links  Absolute http(s) URLs, without fragments
     * @param  list<string>  $colours  Hex colours, most used first
     * @param  list<string>  $fonts  Font family names
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $text,
        public array $links,
        public array $colours,
        public array $fonts,
    ) {}

    public static function extract(string $html, string $url): self
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // The XML declaration makes libxml read the page as UTF-8.
            $document->loadHTML('<?xml encoding="UTF-8">'.mb_scrub($html, 'UTF-8'), LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable) {
            return new self('', '', '', [], [], []);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $title = self::clean((string) $xpath->evaluate('string(//title)'));
        $description = self::clean((string) $xpath->evaluate('string(//meta[@name="description"]/@content)'));
        $colours = self::colours($xpath);
        $fonts = self::fonts($xpath);
        $links = self::links($xpath, $url);

        foreach (iterator_to_array($xpath->query('//'.implode('|//', self::DROPPED)) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $hidden = '//*[@hidden or @aria-hidden="true" or contains(translate(@style, " ", ""), "display:none") or @role="navigation" or @role="banner" or @role="contentinfo" or @role="dialog" or @role="alertdialog"]';

        foreach (iterator_to_array($xpath->query($hidden) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('//*[@id or @class or @aria-label]') ?: []) as $node) {
            if ($node instanceof DOMElement && self::isBoilerplate($node)) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = self::mainElement($xpath);
        $text = $root === null ? '' : DocumentText::clean(self::text($root));

        return new self($title, $description, $text, $links, $colours, $fonts);
    }

    private static function isBoilerplate(DOMElement $element): bool
    {
        // The page's own wrapper may carry such a class; its content is not boilerplate.
        if (in_array(strtolower($element->localName), ['html', 'body', 'main', 'article'], true)) {
            return false;
        }

        $words = implode(' ', [$element->getAttribute('id'), $element->getAttribute('class'), $element->getAttribute('aria-label')]);

        return preg_match(self::BOILERPLATE, $words) === 1;
    }

    private static function mainElement(DOMXPath $xpath): ?DOMNode
    {
        $main = $xpath->query('//main | //*[@role="main"]');

        if ($main !== false && $main->length > 0) {
            return $main->item(0);
        }

        $largest = null;

        foreach ($xpath->query('//article') ?: [] as $article) {
            if ($largest === null || mb_strlen($article->textContent) > mb_strlen($largest->textContent)) {
                $largest = $article;
            }
        }

        return $largest ?? $xpath->query('//body')?->item(0);
    }

    /**
     * The text under a node, with a line break around each block element.
     */
    private static function text(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent;
            } elseif ($child instanceof DOMElement) {
                $block = in_array(strtolower($child->localName), self::BLOCKS, true);
                $inner = self::text($child);
                $text .= $block ? "\n{$inner}\n" : ($child->localName === 'td' || $child->localName === 'th' ? " {$inner} |" : $inner);
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private static function links(DOMXPath $xpath, string $url): array
    {
        $links = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement || str_contains(strtolower($anchor->getAttribute('rel')), 'nofollow')) {
                continue;
            }

            $href = trim($anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript|data):/i', $href) === 1) {
                continue;
            }

            try {
                $absolute = (string) preg_replace('/#.*$/', '', UriResolver::resolve($href, $url));
            } catch (Throwable) {
                continue;
            }

            if (preg_match('#^https?://#i', $absolute) === 1) {
                $links[$absolute] = true;
            }

            if (count($links) >= 300) {
                break;
            }
        }

        return array_keys($links);
    }

    /**
     * The colours the page's markup uses most: theme-color first, then hex
     * values in inline CSS, black, white and greys left out.
     *
     * @return list<string>
     */
    private static function colours(DOMXPath $xpath): array
    {
        $counts = [];
        $css = '';

        foreach ($xpath->query('//style | //*[@style]') ?: [] as $node) {
            $css .= ($node instanceof DOMElement && $node->localName !== 'style' ? $node->getAttribute('style') : $node->textContent)."\n";

            if (strlen($css) > 300000) {
                break;
            }
        }

        preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $css, $matches);

        foreach ($matches[1] as $hex) {
            $colour = self::colour($hex);

            if ($colour !== null) {
                $counts[$colour] = ($counts[$colour] ?? 0) + 1;
            }
        }

        arsort($counts);
        $theme = self::colour(ltrim((string) $xpath->evaluate('string(//meta[@name="theme-color"]/@content)'), '#'));

        return array_slice(array_values(array_unique(array_filter([$theme, ...array_keys($counts)]))), 0, 12);
    }

    /**
     * "#rrggbb" for a 3 or 6 digit hex value, or null for anything else and
     * for black, white and greys (no brand colour).
     */
    private static function colour(string $hex): ?string
    {
        $hex = strtolower(trim($hex));

        if (preg_match('/^([0-9a-f]{3}|[0-9a-f]{6})$/', $hex) !== 1) {
            return null;
        }

        $hex = strlen($hex) === 3 ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex;
        $channels = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];

        return max($channels) - min($channels) < 16 ? null : "#{$hex}";
    }

    /**
     * @return list<string>
     */
    private static function fonts(DOMXPath $xpath): array
    {
        $fonts = [];

        foreach ($xpath->query('//link[contains(@href, "fonts.googleapis.com")]/@href') ?: [] as $href) {
            preg_match_all('/family=([^&:]+)/', html_entity_decode($href->textContent), $families);

            foreach ($families[1] as $family) {
                $fonts[] = trim(str_replace('+', ' ', urldecode($family)));
            }
        }

        $css = '';

        foreach ($xpath->query('//style') ?: [] as $style) {
            $css .= $style->textContent."\n";
        }

        preg_match_all('/font-family\s*:\s*([^;}"]+|"[^"]+")/i', mb_substr($css, 0, 300000), $declarations);

        foreach ($declarations[1] as $declaration) {
            $first = trim(explode(',', $declaration)[0], " \t\"'");

            if ($first !== '' && preg_match('/^(inherit|initial|unset|sans-serif|serif|monospace|system-ui|var\(.*)$/i', $first) !== 1) {
                $fonts[] = $first;
            }
        }

        return array_slice(array_values(array_unique(array_filter($fonts, fn (string $font): bool => mb_strlen($font) <= 60))), 0, 6);
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
