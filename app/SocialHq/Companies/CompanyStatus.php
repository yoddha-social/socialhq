<?php

declare(strict_types=1);

namespace App\SocialHq\Companies;

enum CompanyStatus: string
{
    /** Asked to start a company; a platform admin hasn't decided yet. */
    case Pending = 'pending';

    case Approved = 'approved';

    case Declined = 'declined';

    /** Was approved; a platform admin stopped it. Nobody in it can work. */
    case Suspended = 'suspended';
}
