<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use DOMDocument;

/**
 * Parses XML we didn't write (an Office part, a sitemap, an SVG logo)
 * without network access and without any DTD, which is how entity bombs
 * and external entities get in. The DTD is looked for in the text and, as
 * parsed, on the document: a file in another encoding (UTF-16) hides its
 * DOCTYPE from the text check, never from the parser.
 */
final class SafeXml
{
    /**
     * The document, or null when it isn't well-formed XML or has a DTD.
     */
    public static function load(string $xml): ?DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document->doctype === null ? $document : null;
    }
}
