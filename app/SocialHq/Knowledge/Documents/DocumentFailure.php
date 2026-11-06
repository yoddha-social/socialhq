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

    public function exception(): DocumentUnreadable
    {
        return new DocumentUnreadable($this);
    }
}
