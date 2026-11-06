<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

use Illuminate\Database\Eloquent\Model;

/**
 * A page of the brand's website as last read: its cleaned text, and what
 * tells us next time whether it changed (ETag, Last-Modified, a hash of
 * the HTML). Only the pages of the latest run are kept.
 */
class CrawledPage extends Model
{
    protected $table = 'shq_website_pages';

    protected $guarded = ['id'];
}
