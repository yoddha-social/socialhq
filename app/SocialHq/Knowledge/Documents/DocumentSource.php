<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

/**
 * Where a knowledge document's text comes from.
 */
enum DocumentSource: string
{
    case Upload = 'upload';
    case GoogleDoc = 'google_doc';
    case GoogleSheet = 'google_sheet';
    case GoogleSlides = 'google_slides';
    /** The cleaned pages of the brand's website, kept from "Read our website". */
    case Website = 'website';

    public function isGoogle(): bool
    {
        return in_array($this, [self::GoogleDoc, self::GoogleSheet, self::GoogleSlides], true);
    }

    /**
     * Documents that count toward the per-brand limit: the website's text is one extra.
     */
    public function countsTowardLimit(): bool
    {
        return $this !== self::Website;
    }
}
