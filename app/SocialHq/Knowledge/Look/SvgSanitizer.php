<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Look;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Keeps only the drawing of an SVG logo: shapes, text, gradients and
 * styles from an allowlist. Scripts, event handlers, foreign content,
 * embedded images, links out of the file and CSS that loads anything are
 * removed, and a DOCTYPE (entities) refuses the file. What comes out can
 * be served as an image without running anything.
 */
final class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask',
        'pattern', 'style', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'feflood',
        'fecomposite', 'femerge', 'femergenode', 'fedropshadow',
    ];

    /** Elements that hold the drawing: a file with none of them is no logo. */
    private const DRAWING = ['path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'use'];

    private const ATTRIBUTES = [
        'id', 'class', 'style', 'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr',
        'width', 'height', 'viewbox', 'preserveaspectratio', 'points', 'transform', 'fill', 'fill-opacity',
        'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'clip-path', 'clip-rule', 'mask',
        'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod',
        'patternunits', 'patterncontentunits', 'patterntransform', 'maskunits', 'maskcontentunits',
        'clippathunits', 'filterunits', 'primitiveunits', 'font-family', 'font-size', 'font-weight', 'font-style',
        'text-anchor', 'dominant-baseline', 'letter-spacing', 'word-spacing', 'display', 'visibility', 'version',
        'href', 'in', 'in2', 'result', 'stddeviation', 'dx', 'dy', 'mode', 'type', 'values', 'operator', 'k1',
        'k2', 'k3', 'k4', 'flood-color', 'flood-opacity', 'color', 'xml:space', 'filter', 'paint-order',
        'vector-effect', 'shape-rendering', 'text-rendering', 'alignment-baseline',
    ];

    /**
     * The sanitised SVG, or null when the file isn't a usable SVG.
     */
    public static function sanitize(string $content): ?string
    {
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        if (! $root instanceof DOMElement || strtolower($root->localName) !== 'svg' || ! in_array($root->namespaceURI, [self::SVG_NS, null], true)) {
            return null;
        }

        self::clean($root);

        foreach (self::DRAWING as $name) {
            if ($document->getElementsByTagName($name)->length > 0) {
                $root->setAttribute('xmlns', self::SVG_NS);

                return (string) $document->saveXML($root);
            }
        }

        return null;
    }

    private static function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if ($attribute instanceof DOMAttr && ! self::allowedAttribute($attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            self::cleanChild($element, $child);
        }
    }

    private static function cleanChild(DOMElement $parent, DOMNode $child): void
    {
        if ($child instanceof DOMElement) {
            $allowed = in_array($child->namespaceURI, [self::SVG_NS, null], true)
                && in_array(strtolower($child->localName), self::ELEMENTS, true);

            if (! $allowed || (strtolower($child->localName) === 'style' && ! self::safeCss($child->textContent))) {
                $parent->removeChild($child);

                return;
            }

            self::clean($child);

            return;
        }

        // Text and CDATA stay (text of <text>, CSS of <style>); comments and processing instructions go.
        if (! in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
            $parent->removeChild($child);
        }
    }

    private static function allowedAttribute(DOMAttr $attribute): bool
    {
        $name = strtolower($attribute->localName ?? $attribute->name);
        $value = trim($attribute->value);

        if ($attribute->namespaceURI === self::XLINK_NS) {
            return $name === 'href' && str_starts_with($value, '#');
        }

        if ($attribute->namespaceURI !== null && $attribute->prefix !== 'xml') {
            return false;
        }

        $qualified = $attribute->prefix === 'xml' ? "xml:{$name}" : $name;

        if (! in_array($qualified, self::ATTRIBUTES, true)) {
            return false;
        }

        if ($name === 'href') {
            return str_starts_with($value, '#');
        }

        return self::safeCss($value);
    }

    /**
     * CSS (or an attribute value) that loads nothing and runs nothing: url()
     * only to a fragment of the file itself, no @import, scripts or markup.
     */
    private static function safeCss(string $css): bool
    {
        $lower = strtolower((string) preg_replace('/\s+|\\\\/', '', $css));

        if (str_contains($lower, '@import') || str_contains($lower, 'javascript:') || str_contains($lower, 'expression(')
            || str_contains($lower, '<') || str_contains($lower, 'behavior:') || str_contains($lower, '-moz-binding')) {
            return false;
        }

        preg_match_all('/url\(([^)]*)\)/', $lower, $urls);

        foreach ($urls[1] as $url) {
            if (! str_starts_with(trim($url, '\'"'), '#')) {
                return false;
            }
        }

        return true;
    }
}
