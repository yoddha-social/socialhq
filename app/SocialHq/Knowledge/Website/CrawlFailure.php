<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

/**
 * Why a "Read our website" run stopped. The value is what the page
 * translates (socialhq_knowledge.website.failures.*).
 */
enum CrawlFailure: string
{
    /** The site didn't answer, or not with a web page. */
    case Unreachable = 'unreachable';

    /** The address is on a private network (or resolves to one). */
    case PrivateNetwork = 'private_network';

    /** robots.txt asks robots not to read the site. */
    case Robots = 'robots';

    /** Pages were read but held no text (only scripts or images). */
    case NoText = 'no_text';

    case AiUnavailable = 'ai_unavailable';

    case AiFailed = 'ai_failed';

    /** The admin who started it may no longer change brand knowledge. */
    case NoAccess = 'no_access';

    public function exception(): CrawlStopped
    {
        return new CrawlStopped($this);
    }
}
