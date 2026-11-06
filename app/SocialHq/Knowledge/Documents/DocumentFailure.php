<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

/**
 * Why a document couldn't be read. The value is what the page translates
 * (socialhq_knowledge.documents.failures.*); no exception text ever reaches
 * the user.
 */
enum DocumentFailure: string
{
    /** No text layer: a scan or images only. */
    case NoText = 'no_text';

    /** Password-protected or encrypted. */
    case Protected = 'protected';

    /** Expands to more than we read (a zip bomb, or a huge archive). */
    case TooBig = 'too_big';

    /** Damaged, or a format we couldn't parse. */
    case Unreadable = 'unreadable';

    /** A Google link the app may not read: not shared with "Anyone with the link". */
    case NotShared = 'not_shared';

    /** A Google link to a file that isn't there (deleted, or a wrong link). */
    case NotFound = 'not_found';

    /** Google couldn't be reached, or took too long. */
    case LinkUnreachable = 'link_unreachable';

    public function exception(): DocumentUnreadable
    {
        return new DocumentUnreadable($this);
    }
}
