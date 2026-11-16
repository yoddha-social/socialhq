<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\PlanOptions;
use Illuminate\Support\Collection;

/**
 * Who a brief can be assigned to: the brand's people, as PlanOptions::owners()
 * defines them (the members and the account owner).
 */
final class BriefTeam
{
    /**
     * @return Collection<int, User>
     */
    public static function members(Workspace $workspace): Collection
    {
        return PlanOptions::owners($workspace);
    }

    /**
     * @return list<string>
     */
    public static function ids(Workspace $workspace): array
    {
        return self::members($workspace)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }
}
