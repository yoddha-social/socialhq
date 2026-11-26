<?php

declare(strict_types=1);

namespace App\SocialHq\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A date every brand of the account shares that one brand has no use for:
 * hidden from that brand's events, plan and planner, and from no one else.
 */
class EventHide extends Model
{
    protected $table = 'shq_event_hides';

    protected $guarded = ['id'];
}
