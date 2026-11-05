<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Who a brief can be assigned to: the workspace's members and its owner.
 */
final class BriefTeam
{
    /**
     * @return Collection<int, User>
     */
    public static function members(Workspace $workspace): Collection
    {
        $members = $workspace->members()->with('avatarMedia')->get();
        $owner = $workspace->owner()->with('avatarMedia')->first();

        return $members
            ->when($owner !== null, fn (Collection $users) => $users->push($owner))
            ->unique('id')
            ->sortBy(fn (User $user): string => mb_strtolower((string) $user->name))
            ->values();
    }

    /**
     * @return list<string>
     */
    public static function ids(Workspace $workspace): array
    {
        return self::members($workspace)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }
}
