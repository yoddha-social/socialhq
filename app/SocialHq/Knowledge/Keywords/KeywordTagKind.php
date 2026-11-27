<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

/**
 * What a keyword is tagged with: one of the brand's content pillars, one of
 * its products or services (a knowledge entry of that kind), an event of
 * the Events page (the same occasion every year) or a range of months (a
 * season without an event, like the end of the tax year). A keyword with no
 * tag is general.
 */
enum KeywordTagKind: string
{
    case Pillar = 'pillar';
    case Product = 'product';
    case Event = 'event';
    case Months = 'months';

    /**
     * An event and a range of months are both occasions: when the post goes out.
     */
    public function isOccasion(): bool
    {
        return $this === self::Event || $this === self::Months;
    }
}
