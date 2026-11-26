<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * A plan's month, as a date: the first day, at midnight UTC so its date
 * string never shifts. Pages name it "YYYY-MM"; without one the user's
 * current month in their own time zone.
 */
final class PlanMonth
{
    /**
     * The month a route names; none means the user's current month, and one
     * that isn't a month ("2026-13") is not found.
     */
    public static function parse(?string $month, User $user): CarbonImmutable
    {
        if ($month === null || $month === '') {
            return self::current($user);
        }

        return self::tryParse($month) ?? abort(Response::HTTP_NOT_FOUND);
    }

    /**
     * A "YYYY-MM" month from 2000 to 2100, or null for anything else (a
     * filter that ignores what it can't read).
     */
    public static function tryParse(mixed $month): ?CarbonImmutable
    {
        if (! is_string($month) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $parts) !== 1 || (int) $parts[1] < 2000 || (int) $parts[1] > 2100) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', "{$month}-01", 'UTC');
    }

    /**
     * Today's date (Y-m-d) in the person's time zone.
     */
    public static function today(User $user): string
    {
        return CarbonImmutable::now(Timezone::normalize($user->timezone))->toDateString();
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
        // createOrFirst: two first writes at once both end on the one row the unique index allows.
        return self::find($workspace, $month) ?? ContentPlan::query()->createOrFirst(
            ['workspace_id' => $workspace->id, 'month' => $month->toDateString()],
            [
                'status' => PlanStatus::Draft,
                'generation_status' => GenerationStatus::Idle,
                'created_by' => $user->id,
            ],
        );
    }
}
