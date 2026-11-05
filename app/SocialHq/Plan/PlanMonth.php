<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Timezone;
use Carbon\CarbonImmutable;

/**
 * A plan's month, as a date: the first day, at midnight UTC so its date
 * string never shifts. Pages name it "YYYY-MM"; without one the user's
 * current month in their own time zone.
 */
final class PlanMonth
{
    public static function parse(?string $month, User $user): CarbonImmutable
    {
        if (is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m-d', "{$month}-01", 'UTC');
        }

        return self::current($user);
    }

    public static function current(User $user): CarbonImmutable
    {
        $today = CarbonImmutable::now(Timezone::normalize($user->timezone));

        return CarbonImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-01'), 'UTC');
    }

    public static function end(CarbonImmutable $month): CarbonImmutable
    {
        return $month->endOfMonth()->startOfDay();
    }

    public static function key(CarbonImmutable $month): string
    {
        return $month->format('Y-m');
    }

    public static function find(Workspace $workspace, CarbonImmutable $month): ?ContentPlan
    {
        return ContentPlan::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('month', $month->toDateString())
            ->first();
    }

    /**
     * The month's plan, created on the first write (a view never creates one).
     */
    public static function findOrCreate(Workspace $workspace, CarbonImmutable $month, User $user): ContentPlan
    {
        return self::find($workspace, $month) ?? ContentPlan::query()->create([
            'workspace_id' => $workspace->id,
            'month' => $month->toDateString(),
            'status' => PlanStatus::Draft,
            'generation_status' => GenerationStatus::Idle,
            'created_by' => $user->id,
        ]);
    }
}
